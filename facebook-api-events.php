<?php
/*
Plugin Name: Facebook Events Plugin
Description: Facebook Events Plugin that pulls the event information from your Facebook Business Page via the API and displays it on a WordPress based website.
Plugin URI: http://www.niklasdahlqvist.com
Author: Niklas Dahlqvist
Author URI: http://www.niklasdahlqvist.com
Version: 1.0.0
Requires at least: 4.2
License: GPL
*/

/*
   Copyright 2015  Niklas Dahlqvist  (email : dalkmania@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Support local development (symlinks)
// Via Alex King @ http://alexking.org/blog/2011/12/15/wordpress-plugins-and-symlinks
$my_plugin_file = __FILE__;

if (isset($plugin)) {
    $my_plugin_file = $plugin;
}
else if (isset($mu_plugin)) {
    $my_plugin_file = $mu_plugin;
}
else if (isset($network_plugin)) {
    $my_plugin_file = $network_plugin;
}


/**
* Ensure class doesn't already exist
*/
if(! class_exists ("Facebook_Events_Plugin") ) {

  class Facebook_Events_Plugin {
    private $options;
    private $apiBaseUrl;
    private $apiOauthUrl;

    /**
     * Start up
     */
    public function __construct()
    {
        $this->options = get_option( 'facebook_settings' );
        $this->apiBaseUrl = 'https://graph.facebook.com/v2.4/';
        $this->apiOauthUrl = 'https://graph.facebook.com/oauth/access_token';
        $this->facebook_api_app_id = $this->options['facebook_api_app_id'];
        $this->facebook_api_app_secret = $this->options['facebook_api_app_secret'];
        $this->facebook_page_id = $this->options['facebook_page_id'];

        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );

        add_action('admin_print_styles', array($this,'plugin_admin_styles'));
        add_action( 'admin_enqueue_scripts', array( $this, 'plugin_admin_js' ) );

        add_shortcode('display_fb_events', array( $this,'displayEventsShortCode') );
    }

    public function getOauthAccessToken() {

      $data = '?type=client_cred'
      . "&client_id=" . $this->facebook_api_app_id
      . "&client_secret=" . $this->facebook_api_app_secret;

      $token = $this->sendRequest( $this->apiOauthUrl, $data );
      
      return $token;

    }

    public function getEventData() {
      $token = $this->getOauthAccessToken();

      $data = '?fields=picture.height(800).width(800),cover,events.fields(name,description,timezone,start_time,end_time,picture.height(800).width(800),cover)'
      . '&' . $token;

      $data = $this->sendRequest( $this->apiBaseUrl . $this->facebook_page_id . '/' , $data );

      return json_decode($data);

    }

    function datesort($a, $b) {
      return($a['start_time']) - ($b['start_time']);
    }

    public function getUpcomingEventData() {
      // Get any existing copy of our transient data
      if ( false === ( $facebook_events = get_transient( 'facebook_events' ) ) ) {
        // It wasn't there, so make a new API Request and regenerate the data
        $facebook_events = $this->getEventData();

        $event_data = array();
        date_default_timezone_set(get_option('timezone_string'));
        $now = time(); // Get Current Timestamp

        if($facebook_events->picture->data->url != '') {
          $default_event_image = $facebook_events->picture->data->url;
        }

        if($facebook_events->cover->source != '') {
          $default_event_cover = $facebook_events->picture->data->url;
        }

        foreach( $facebook_events->events->data as $item ) {
          if((strtotime($item->start_time) <=  $now &&  (strtotime($item->end_time) >= $now)  OR (strtotime($item->start_time) >=  $now) &&  (strtotime($item->end_time) >= $now)) )   {
            
            if($item->description != '') {
              $description = $item->description;
            } else {
              $description = '';
            }

            if($item->start_time != '') {
              $start_time = strtotime($item->start_time);
            } else {
              $start_time = '';
            }

            if($item->end_time != '') {
              $end_time = strtotime($item->end_time);
            } else {
              $end_time = '';
            }

            if($item->cover->source != '') {
              $cover = $item->cover->source;
            } else {
              $cover = $default_event_cover;
            }

            if($item->picture->data->url != '') {
              $picture = $item->picture->data->url;
            } else {
              $picture = $default_event_image; 
            }

            $facebook_item = array(
              'name' => $item->name,
              'description' => $description,
              'start_time' => $start_time,
              'end_time' => $end_time,
              'cover' => $cover,
              'picture' => $picture
            );

            array_push($event_data, $facebook_item);

          }

          //Sort the Array by date
          usort($event_data, array($this, 'datesort'));
        }

        // It wasn't there, so save the transient for 1 hour
        $this->storeFacebookEventData($event_data);

      } else {
        // Get any existing copy of our transient data
        $event_data = unserialize(get_transient( 'facebook_events' ));
      }

      // Finally return the data
      return $event_data;
    }
    
    // Send Curl Request to Facebook Endpoints and return the response
    public function sendRequest( $url, $data ) { 
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url . $data);
      curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $response = curl_exec($ch);

      return $response;
    }

    

    public function plugin_admin_styles() {
        wp_enqueue_style('admin-style', $this->getBaseUrl() . '/assets/css/plugin-admin-styles.css');

    }

    public function plugin_admin_js() {
        wp_register_script( 'admin-js', $this->getBaseUrl() . '/assets/js/plugin-admin-scripts.js' );
        wp_enqueue_script( 'admin-js' );
    }

    

    /**
     * Add options page
     */
    public function add_plugin_page()
    {
        // This page will be under "Settings"
        add_management_page(
            'Facebook Events Settings Admin', 
            'Facebook Events Settings', 
            'manage_options', 
            'facebook-events-settings-admin', 
            array( $this, 'create_admin_page' )
        );
    }

    /**
     * Options page callback
     */
    public function create_admin_page()
    {
        // Set class property
        $this->options = get_option( 'facebook_settings' );
        ?>
        <div class="wrap facebook-events-settings">
            <h2>Facebook Events Settings</h2>           
            <form method="post" action="options.php">
            <?php
                // This prints out all hidden setting fields
                settings_fields( 'facebook_settings_group' );   
                do_settings_sections( 'facebook-events-settings-admin' );
                submit_button();
            ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init()
    {        
        register_setting(
            'facebook_settings_group', // Option group
            'facebook_settings', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'facebook_section', // ID
            'Facebook Events Settings', // Title
            array( $this, 'print_section_info' ), // Callback
            'facebook-events-settings-admin' // Page
        );  

        add_settings_field(
            'facebook_api_app_id', // ID
            'Facebook App ID', // Title 
            array( $this, 'facebook_api_app_id_callback' ), // Callback
            'facebook-events-settings-admin', // Page
            'facebook_section' // Section           
        );      

        add_settings_field(
            'facebook_api_app_secret', 
            'Facebook App Secret', 
            array( $this, 'facebook_api_app_secret_callback' ), 
            'facebook-events-settings-admin', 
            'facebook_section'
        );


        add_settings_field(
            'facebook_page_id', 
            'Facebook Page ID', 
            array( $this, 'facebook_page_id_callback' ), 
            'facebook-events-settings-admin', 
            'facebook_section'
        );
 
    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {
        $new_input = array();
        if( isset( $input['facebook_api_app_id'] ) )
            $new_input['facebook_api_app_id'] = sanitize_text_field( $input['facebook_api_app_id'] );

        if( isset( $input['facebook_api_app_secret'] ) )
            $new_input['facebook_api_app_secret'] = sanitize_text_field( $input['facebook_api_app_secret'] );

        if( isset( $input['facebook_page_id'] ) )
            $new_input['facebook_page_id'] = sanitize_text_field( $input['facebook_page_id'] );

        return $new_input;
    }

    /** 
     * Print the Section text
     */
    public function print_section_info() {
      print 'Enter your settings below:';
    }

    /** 
     * Get the settings option array and print one of its values
     */
    public function facebook_api_app_id_callback()
    {
        printf(
            '<input type="text" id="facebook_api_app_id" class="regular-text" name="facebook_settings[facebook_api_app_id]" value="%s" />',
            isset( $this->options['facebook_api_app_id'] ) ? esc_attr( $this->options['facebook_api_app_id']) : ''
        );
    }

    /** 
     * Get the settings option array and print one of its values
     */
    public function facebook_api_app_secret_callback()
    {
        printf(
            '<input type="text" id="facebook_api_app_secret" class="regular-text" name="facebook_settings[facebook_api_app_secret]" value="%s" />',
            isset( $this->options['facebook_api_app_secret'] ) ? esc_attr( $this->options['facebook_api_app_secret']) : ''
        );
    }

    public function facebook_page_id_callback() {
        printf(
            '<input type="text" id="facebook_page_id" name="facebook_settings[facebook_page_id]" value="%s" />',
            isset( $this->options['facebook_page_id'] ) ? esc_attr( $this->options['facebook_page_id']) : ''
        );
        
    }

    public function storeFacebookEventData($facebook_data) {

      // Get any existing copy of our transient data
      if ( false === ( $facebook_events = get_transient( 'facebook_events' ) ) ) {
        // It wasn't there, so regenerate the data and save the transient for 2 hours
        $facebook_events = serialize($facebook_data);
        set_transient( 'facebook_events', $facebook_events, 1 * HOUR_IN_SECONDS );
      }
      
    }

    public function flushStoredInformation() {
      //Delete transients to force a new pull from the API
      delete_transient( 'facebook_events' );
    }

    public function displayEventsShortCode($atts, $content = null) {
      $args = shortcode_atts(array(
        'count' => 5,
        'display_information' => 'yes'
        ), $atts);

      date_default_timezone_set(get_option('timezone_string'));
      $output = '';
      $events = $this->getUpcomingEventData();
      
      if( !empty( $events ) ) {
        $i = 1;
        $output .= '<div class="event-list">';
        
        foreach ($events as $event) {
          if($i <= $args['count']) {
            $output .= '<div class="event-entry">';
            $output .= '<div class="image-date">';
            $output .= '<div class="image">';
            $output .= '<img alt="' . $event['name'] .'" src="' . $event['cover'] .'">';
            $output .= '</div>';
            $output .= '<div class="date">';
            $output .= '<span class="week">' . date('l', $event['start_time']) .'</span>';
            $output .= '<span class="day">' . date('j', $event['start_time']) .'</span>';
            $output .= '<span class="month-year">' . date('M', $event['start_time']) .' / ' . date('Y', $event['start_time']) .'</span>';
            $output .= '<span class="time">' . date('g:i A', $event['start_time']) .'</span>';
            $output .= '</div>'; 
            $output .= '</div>';

            $output .= '<div class="event-description">';
            $output .= '<h2>' . $event['name'] .'</h2>';
            $output .= '<p>' . $event['description'] .'</p>';
            $output .= '</div>';
            $output .= '</div>';
          }
          $i++;
        }

        $output .= '</div>';
      } else {

        $output .= '<div class="event-notice info">';
        $output .= '<h1>No Upcoming Events Planned</h1>';
        $output .= '</div>';

      }

      return $output;
    }

    

   //Returns the url of the plugin's root folder
    protected function getBaseUrl(){
      return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected function getBasePath(){
      $folder = basename(dirname(__FILE__));
      return WP_PLUGIN_DIR . "/" . $folder;
    }


  } //End Class

  /**
   * Instantiate this class to ensure the action and shortcode hooks are hooked.
   * This instantiation can only be done once (see it's __construct() to understand why.)
   */
  new Facebook_Events_Plugin();

} // End if class exists statement