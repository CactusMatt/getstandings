<?php
/*
Plugin Name: TablePress Extension: Get Standings
Plugin URI: http://the-fam.us/MatthewBlog/extensions/getstandings
Description: Extension for TablePress to a) fetch a URL from TX High School Baseball for 14-6A standings, and b) format the resulting JSON into a TablePress table at the given table-id.
Version: 1.0
Author: Matthew George
Author URI: http://the-fam.us/MatthewBlog
 */
/*  Copyright 2015 Matthew D. George  (email : matthewdgeorge@the-fam.us)

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

/**
 * Created by PhpStorm.
 * User: Matthew
 * Date: 2015/03/20
 * Time: 9:19 AM
 *
 * This code reads from a YQL-generated URL, and fetches the JSON data back via Curl
 *
 * It reads from 'table' that contains: team-name, wins, losses  -- and a bunch of other (un-needed) stuff
 *
 * It populates abd returns a TablePress table formatted as:
 * Row-0: header info. This is generally ALREADY populated in the table passed to the plug-in, so this plug-in doesn't modify it
 * Row-1: 1st place team name (column 0),Wins (column 1), losses (column 2), win-percentage as calculated from wins/(wins+losses) in column 3
 * Row-2: 2nd place team, wins, losses, win %
 * Row-3: 3rd place team, wins, losses, win %
 * etc. until the input table data is exhausted
 *
 * Version 1.0 uses the WP_Schedule_Event API to 'cache' the scraped table JSON into the WP_Options DB table, and a new scraped source table JSON record is theoretically fetched every hour.
 * On any particular web-visit (and render of the GetStandings TablePress table), the source table JSON is simply read from the WP_Options DB.
 */
// Prohibit direct script loading
defined( 'ABSPATH' ) || die( 'No direct script access allowed!' );

register_activation_hook( __FILE__, array('TablePress_Get_Standings','getStandings_activation' ) );
/**
 * Init TablePress_Get_Standings, this hook calls our (static) class constructor 'init'
 */
add_action( 'tablepress_run', array( 'TablePress_Get_Standings', 'init' ) );
add_action( 'tablepress_run', array( 'TablePress_Get_Standings', 'getStandings_setup_schedule' ));
// add re-populator to WP 'Scheduler'
add_action( 'TablePress_GetStandings_update_table_event', array('TablePress_Get_Standings', 'updateTable' ) );


register_deactivation_hook( __FILE__, array('TablePress_Get_Standings','plugin_deactivate') );


/**
 * Class that contains the TablePress Row Filtering functionality
 * @author Tobias Bäthge
 * @since 1.0
 */
class TablePress_Get_Standings
{
    protected static $table_json;
    protected static $table_json_default = '{"query":{"count":27,"created":"2015-03-25T19:16:04Z","lang":"en-US","results":{"td":[{"class":"team_name_td_yo","width":"250px","content":"Austin Bowie BullPuppies"},{"width":"30px","content":"5"},{"width":"30px","content":"0"},{"class":"team_name_td_yo","width":"250px","content":"Buda Hays Rebels"},{"width":"30px","content":"5"},{"width":"30px","content":"1"},{"class":"team_name_td_yo","width":"250px","content":"Austin Lake Travis Cavaliers"},{"width":"30px","content":"4"},{"width":"30px","content":"1"},{"class":"team_name_td_yo","width":"250px","content":"Austin Maroons"},{"width":"30px","content":"3"},{"width":"30px","content":"2"},{"class":"team_name_td_yo","width":"250px","content":"Kyle Lehman Lobos"},{"width":"30px","content":"2"},{"width":"30px","content":"3"},{"class":"team_name_td_yo","width":"250px","content":"Austin Anderson Trojans"},{"width":"30px","content":"2"},{"width":"30px","content":"4"},{"class":"team_name_td_yo","width":"250px","content":"Austin Akins Eagles"},{"width":"30px","content":"2"},{"width":"30px","content":"4"},{"class":"team_name_td_yo","width":"250px","content":"Austin Westlake Chaparrals"},{"width":"30px","content":"1"},{"width":"30px","content":"4"},{"class":"team_name_td_yo","width":"250px","content":"Del Valle Cardinals"},{"width":"30px","content":"0"},{"width":"30px","content":"5"}]}}}';
    

    protected static $yqlQueryURL = "https://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20html%20where%20xpath%3D%22%2F%2Ftable%5B%40id%3D'standings_table'%5D%2Ftbody%2Ftr%2Ftd%5Bposition()%20%3C%3D%203%5D%22%20and%20%0Aurl%3D%22http%3A%2F%2Ftxhighschoolbaseball.com%2F6a%2F14-6a%2F%22%0A&format=json";

    public static function getStandings_activation() {
        // executed only when the plug-in is activated

        // turn on the scheduler hook
        self::getStandings_setup_schedule();

        // fetch new JSON data, stash it in wp_options table under unique key
        self::getTableData();

    } // end getStandings_activation

    public static function plugin_deactivate(){

        // remove get_standings_json value when we un-install
        $jsonOption = get_option('tp_get_standings');
        
        if (isset($jsonOption['get_standings_json'])) {
            unset($jsonOption['get_standings_json']);
            update_option('tp_get_standings', $jsonOption);
        }


        // also clean up WP Scheduler function here
        wp_clear_scheduled_hook( 'TablePress_GetStandings_update_table_event' );
    } // end plugin_deactivate

    public static function getResultFromYQL(  ) {
        // returns a json-encoded STRING
        // the returned string must be 'json_decode' in order to use it as a valid json object


//        $yql_ur = ''; // set to empty by default


        $yql_query_url = TablePress_Get_Standings::$yqlQueryURL;


        // set up the cURL
        $session = curl_init();
        curl_setopt($session, CURLOPT_URL, $yql_query_url);
        curl_setopt($session, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($session, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($session, CURLOPT_SSL_VERIFYHOST, false);

        $json = curl_exec($session);
        curl_close($session);

        return $json;
    } // end getResultsFromYQL

    public static function getTableData() {
        /*
         * fetches table data into a private class variable, and updates a key-value pair in the wp_options DB table
         */
        $jsonStr = TablePress_Get_Standings::getResultFromYQL();
        TablePress_Get_Standings::$table_json = $jsonStr;
        
        $jsonOption = get_option('tp_get_standings');
        
        $jsonOption['get_standings_json'] = $jsonStr;
        update_option('tp_get_standings',$jsonOption);

        $errStr = "option tp_get_standings index 'get_standings_json' set to  ".$jsonOption['get_standings_json']." via getTableData\n";
        error_log($errStr);

    } // end getTableData

    /**
     * On an early action hook, check if the hook is scheduled - if not, schedule it.
     */
    public static function getStandings_setup_schedule() {
        if ( !wp_next_scheduled( 'TablePress_GetStandings_update_table_event' ) ) {
            // this event should be kicked off at now + 30 seconds
            wp_schedule_event( current_time('timestamp')+15, 'hourly', 'TablePress_GetStandings_update_table_event');
            // Class mapping must be explicit, as the function executed on scheduled event timeout will be asynchronous to this containing Class
            add_action( 'TablePress_GetStandings_update_table_event', array('TablePress_Get_Standings', 'updateTable' ) );
        }
    } // end getStandings_setup_schedule

    /**
     * On the scheduled action hook, update our table
     *
     * Note that we need to be explicit with Class mappings, because this code is called asynchronously to other elements of TablePress_Get_Standings Class
     */
    public static function updateTable() {
        // update our table on a fixed schedule
        TablePress_Get_Standings::getTableData();
    }


    /**
     * Register necessary plugin filter hooks
     *
     * @since 1.0
     */
    public static function init()
        /*
         * This function is responsible for two things:
         * 1. Register TablePress WP filters. This sets the entry-point for the main-plugin (tablepress_table_raw_render_data),
         * and for invocation-specific shortc-codes (tablepress_shortcode_table_default_shortcode_atts).
         * 2. It installs a WP-specific 'scheduler' task for fetching new scores every 15 minutes. The scores are fetched via a
         * Yahoo Query Language (YQL)-specific URL via Curl, and the JSON results of the fetch are stashed in class variable for use when-ever a
         * web-fetch of this table are requested by the user's browser.
         */
    {
        // Get_Standings does not have any optional short-code attributes yet
        add_filter('tablepress_table_raw_render_data', array(__CLASS__, 'get_standings'), 10, 3);
        add_filter('tablepress_shortcode_table_default_shortcode_atts', array(__CLASS__, 'shortcode_attributes'));


    } // emd init

    /**
     * Add the Extension's parameters as valid [[table /]] Shortcode attributes
     *
     * @since 1.0
     *
     * @param array $default_atts Default attributes for the TablePress [[table /]] Shortcode
     * @return array Extended attributes for the Shortcode
     */
    public static function shortcode_attributes($default_atts)
    {
        /*
         * GetStandings has only two options:
         * 1. 'getstandings' (boolean 'true' or 'false'
         * 2. The YQL-generated URL for which the standings table is based. This assumed to return a valid HTML Table, and not much else. 'standingsYQL_URL'
         * */
        $default_atts['getstandings'] = '';
        $default_atts['standingsYQL_URL'] = '';
        $default_atts['getStandingsDebug'] = '';

        return $default_atts;
    } // end shortcode_attributes


    public static function get_standings( $table,  $render_options) {
        // this is the main 'extension' entry-point
        $standingsOptions = $render_options['getstandings'];
        // $table['data'][0][0] = "Enterered";

        if ( empty($standingsOptions) )
            return $table; // nothing to see here
        if (!$standingsOptions)
            return $table; // option set to false (or non existant

        $gsDebug = $render_options['getStandingsDebug'];

        $yql_url = $render_options['standingsYQL_URL']; // this sets the URL for the table-fetch on the NEXT scheduled fetch (not this one)
        if (!empty($yql_url))
            TablePress_Get_Standings::$yqlQueryURL = $yql_url;

        /*
         * debug info on timer
         */
        $timeNextEvent = wp_next_scheduled( 'TablePress_GetStandings_update_table_event'  );
        if ( !$timeNextEvent ) {
            $newEventTime = current_time('timestamp')+15;

            $eventScheduled = wp_schedule_event( $newEventTime, 'hourly', 'TablePress_GetStandings_update_table_event');
            add_action( 'TablePress_GetStandings_update_table_event', array('TablePress_Get_Standings', 'updateTable' ) );

            if (!empty($gsDebug)) {
                // grab table row-0 for debugging
                $table['data'][0][0] = (is_null($eventScheduled))?"Event re-scheduled: ".date(DATE_RFC2822, $newEventTime):"Event schedule failed: ".date(DATE_RFC2822, $newEventTime);
            }

        }
        else {
            if (!empty($gsDebug)) {
                // grab table row-0 for debugging
                $table['data'][0][0] = "Event already scheduled: ".date(DATE_RFC2822, $timeNextEvent);
                // $table['data'][0][0] = "Event already scheduled: value: ".$timeNextEvent;
                //$table['data'][0][1] = "Debug";
            }

        }

        /*
         * The returned JSON is assumed to contain a repeating set of 'td' records, with keys of 'content' for each 'td' record. The 'td' records will be in sets of three:
         * the first record will contain the team-name
         * the second record will contain the number of wins
         * the third record will contain the number of losses
         *
         * This pattern repeats until the count of 'td' records is exhausted.
         */
        $jsonOption = get_option('tp_get_standings');
        
        /*
         * if the wp_options table entry for 'get_standings_json' has a value, then use it -- the WP Scheduler will periodically repopulate this value.
         * Otherwise, populate the 'get_standings_json' key with the default JSON string.
         */
        if (isset($jsonOption['get_standings_json']))
            $data = $jsonOption['get_standings_json'];
        else
        {
            $data = TablePress_Get_Standings::$table_json_default;
            $jsonOption['get_standings_json'] = $data;
            $updated = (update_option('tp_get_standings', $jsonOption))? 1 : 0;
            error_log( "option tp_get_standings reset to defaults\n");

        }
        $errStr = "option tp_get_standings index 'get_standings_json' reads as  ".$jsonOption['get_standings_json']." via getTableData\n";
        error_log($errStr);

        if( is_string($data) )
            $data = json_decode($data); // if our data is a string, we need to decode into json

        /*
         * For the target 'Table' assume that Row[0] contains header-information, and that we will begin populating the output Table at:
         * Row[1][0] = team-name
         * Row[1][1] = Wins
         * Rows[1]2] = Losses
         *
         * ... (pattern repeats until we have exhausted all input value
         */

        $row = 1; // start output Table population at row 1, Table-header info is in row-0

        $recordCount = $data->query->count;
        for ($i = 0; $i < $recordCount; $i++) {
            // our YQL query returns 3 TD records per team-name (or 'row')
            switch ($i % 3) {
                case 0:
                    // team name goes in column 1
                    $team_name = $data->query->results->td[$i]->content;
                    $table['data'][$row][0] = $team_name;
                    break;
                case 1:
                    // wins FOR goes in column 2
                    $wins  = $data->query->results->td[$i]->content;
                    $table['data'][$row][1] = $wins;
                    break;
                case 2:
                    //losses AGAINST goes in column 3
                    $losses = $data->query->results->td[$i]->content;
                    $table['data'][$row][2] = $losses;


                    // now let's calculate and fill-in win-percentage
                    // invalid input on wins or losses produces an error output (-1)
                    if (is_numeric($wins) && is_numeric($losses) && (($wins + $losses) > 0))
                        $winPercent = $wins/($wins + $losses);
                    else
                        $winPercent = -1;

                    // win-percentage goes in column 4
                    $table['data'][$row][3] = number_format($winPercent, 3);

                    $row++; // move on to the next row of the output Table

                    break;
                default:
                    $table['data'][row][0] = "Invalid TD case!";
            } // end switch
        } // end for

        return $table;
    } // end get_standings

} // end Class TablePress_Get_Standings
?>