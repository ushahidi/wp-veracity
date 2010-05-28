<?php
/*
Plugin Name: WordPress Veracity
Plugin URI: http://appfrica.org
Description: This will enable ranking of your posts by popularity based on Bayesian algorithm; using the behavior of your visitors to determine each post's popularity. You set a value (or use the default value) for every post view, comment, etc. and the popularity of your posts is calculated based on those values. Once you have activated the plugin, you can configure the Popularity Values and View Reports. You can also use the included Widgets and Template Tags to display post popularity and lists of popular posts on your blog.
Version: 1.0
Author: Ivan Kavuma, Jon Gosier
Author URI: http://swift.ushahidi.com

*/

$interest = 'interest';//meta data tags for wp
$scen = 'scenario';
$processdelayinsec=2;//TODO ADD option
$processnow = TRUE;//FALSE;

function bttl_widget()
{
	// Check for the required plugin functions. 
	if (!function_exists('register_sidebar_widget') ){return;}
	
	//Display defaultnum posts picked randomly, weighted based on interest.
	//Records which ones were displayed in the SQL database
	//Checks if we need to process our data based on time interval or user request
	function bttl_display($args)
	{
		global $processdelayinsec, $processnow;
		global $interest, $scen;
		global $wpdb;
		extract($args);
		$wpdb->bttl_data = $wpdb->prefix.'bttl_data';
		$options=get_option('bttl_control');
		$defaultnum = (isset($options['count'])) ? $options['count'] : 4;
		$title = ($options['title']) ? $options['title'] : "Featured" ;
		$testmode = ($options['showscore']) ? TRUE : FALSE;
		$items = pickrandomweighted($defaultnum);
		$timestamp = rand(1000000,2000000);
		$plugindirarr = explode('wp-content',dirname(__FILE__));
		$plugindir = (count($plugindirarr)==2) ? '/wp-content'.$plugindirarr[1] : '/wp-content/plugins';
		//microtime(get_as_float);
		//microtime seemed like a good unique identifier, but its implementation
		//is not standard on all systems
		//rand may collide, but not often and the effect on stats would be negligible

		echo $before_widget;
		echo "$before_title $title $after_title <ul>";
		foreach ($items as $item)
		{
			$ab=get_post_meta($item->ID,$interest,TRUE);
			$score =  ($testmode) ? round(expect($ab),2)." <a href='http://www.srcf.ucam.org/~sea31/what_multi.cgi?plot=plot+%5B0%3A1%5D+x**$ab[0]+*%281-x%29**$ab[1]&amp;button=plot'>plot</a>"  : "";
			echo '<li><a href="' . get_bloginfo('wpurl') . $plugindir.'/bttl.php?guid='.rawurlencode(get_permalink($item->ID)).'&amp;items='.$item->ID.'&amp;stamp='.$timestamp.'">'.$item->post_title." $score".'</a>'.'</li>';
		}
		if ($testmode) echo "<li>".round(expect($ab=get_option($scen)),2)." <a href='http://www.srcf.ucam.org/~sea31/what_multi.cgi?plot=plot+%5B0%3A1%5D+x**$ab[0]+*%281-x%29**$ab[1]&amp;button=plot'>plot</a> </li>";
		echo '</ul> '.$after_widget;
		$table_name=$wpdb->bttl_data;
		
		if ($wpdb->get_var("show tables like '$table_name'") != $table_name)
		{
			 $sql = "CREATE TABLE " . $table_name . " (
			 	timestamp char(20), 
				items BIGINT(20), 
				clicked int(8) NOT NULL DEFAULT 0, 
				key timestamp(timestamp)
				);
			";
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			 dbDelta($sql);
		 }

		//Commented statement is safer, but unnecessary because users can't affect these values. The subsequent statement is more compatible
	    //foreach ($items as $item) $wpdb->insert($table_name,array('timestamp'=>$timestamp,'items'=>$item->ID));
	        foreach ($items as $item) {$id = $item->ID;$wpdb->query("INSERT INTO $table_name (timestamp, items) VALUES ('$timestamp','$id')");}
		$oldest = $wpdb->get_var('SELECT timestamp FROM '.$table_name.' ORDER BY timestamp ASC LIMIT 1');
		$newest = $wpdb->get_var('SELECT timestamp FROM '.$table_name.' ORDER BY timestamp DESC LIMIT 1');
		
		$oldtime=get_option('lastupdatetime');
		$newtime=time();
		if (($newtime - $oldtime > $processdelayinsec) or($processnow==true))
		{
			updateinterest();
			update_option('lastupdatetime',$newtime);
		}
		
	}
	
	//Make sure all the posts have a prior interest setting
	function checkforblanks()
	{
		$defaultprior = array(0, 0);//initializes or resets for interest/scenario tags
	        $defaultscen = array(0, 3);
		global $interest;
		global $scen;
		$all_posts = get_posts('numberposts=-1');
		//RESET
		$options=get_option('bttl_control');
		$resetparams = ($options['reset']==1)? TRUE: FALSE;
		
		if ($resetparams)
		{
			foreach ($all_posts as $post)
			{
				update_option($scen, $defaultscen);
				delete_post_meta($post->ID, $interest);
			}
			//DROP TABLE ADD
			$options['reset']= 0;
			update_option('bttl_control',$options);
		}
		
		foreach($all_posts as $post) {
			if (get_post_meta($post->ID, $interest, TRUE)==FALSE)
				add_post_meta($post->ID, $interest, $defaultprior);
		}
	}
	
	//Pick $number posts at random weighted based on interest
	function pickrandomweighted($number)
	{
		global $interest;
		global $wpdb;
		checkforblanks();
		$numposts = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'publish'");
		$number = floor($number);
		
		if ($number < 0)
			$number = 0;
		if ($number > $numposts)
			$number = $numposts;
		
		$all_posts = get_posts('numberposts=-1');
		$maxinterest = 0;
		foreach ($all_posts as $post)
			$maxinterest+=expect(get_post_meta($post->ID, $interest, TRUE));
		
		for ($i=0; $i < $number ; $i++)
		{
			$pick=rand(0, 10000*$maxinterest)/10000;
			$temp=0;
			foreach ($all_posts as $key=>$post)
			{
				$pickpostkey = $key;
				$temp+=expect(get_post_meta($post->ID, $interest, true));
				if ($temp > $pick)
					break;
			}
			$maxinterest -= expect(get_post_meta($all_posts[$pickpostkey]->ID, $interest, TRUE));
			$pickpost[$i]=$all_posts[$pickpostkey];
			unset($all_posts[$pickpostkey]);
		}
		
		return $pickpost;
	}
	
	
	function expect($beta)
	{
		//Another nice property of Beta distributions: easy expectation values
		return((1+$beta[0])/(2+$beta[0]+$beta[1]));
	}
	
	//Input an array of weights, output the key of a choice made randomly based on the weights
	function randwgt($weightarray)
	{
		$pick = rand(0, 10000*array_sum($weightarray))/10000;
		$temp=0;
		
		foreach ($weightarray as $key=>$i)
		{
			$item=$key;
			$temp+=$i;
			
			if ($temp>$pick)
				break;
		}
		return $item;
	}
	
	//Process raw data into a form that can be used in our updating algorithm
	function processrawdata()
	{
		global $interest;
		global $wpdb;
		$options=get_option('bttl_control');
		$defaultnum = ($options['count']) ? $options['count'] : 4;
		$all_posts=get_posts('numberposts=-1');
		
	       $table_name=$wpdb->prefix.'bttl_data';
	       $timestamp = $wpdb->get_col("select timestamp,items,clicked from $table_name order by timestamp desc limit $defaultnum");
	       $items = $wpdb->get_col("", 1);
	       $clicked = $wpdb->get_col("", 2);
	       $result = array('posts'=>$items, 'clicked'=>$clicked);
	       
	       if ($items)
	       {
		    $wpdb->query("delete from $table_name where timestamp = '$timestamp[0]'");
	       }
	       
	       return $result;
	}
	
	//Update interest based on recent data
	function updateinterest()
	{
		global $interest;
		global $scen;

		$datum = processrawdata();
		while($datum['posts']){
			//update interest
			$somethingclicked = array_sum($datum['clicked'])>0 ? 1 : 0;

			$probnoneinteresting = 1;
			foreach ($datum['posts'] as $postid)
			{
				$probnoneinteresting *= (1-expect(get_post_meta($postid, $interest, true)));
			}
			$j=0;
			foreach($datum['posts'] as $postid)
			{
				$probthisnotinteresting = (1-expect(get_post_meta($postid, $interest, true)));
				$nothingterm = 1/(1+((1/expect(get_option($scen))-1)/($probnoneinteresting/$probthisnotinteresting)));
				$oldinterest=get_post_meta($postid, $interest, true);
				$adda = ($datum['clicked'][$j]);
				$addb = (1-$datum['clicked'][$j])*($somethingclicked+(1-$somethingclicked)*$nothingterm);
				update_post_meta($postid, $interest, array($oldinterest[0]+$adda, $oldinterest[1]+$addb));

                                //update the popularity score ########### Ivan #####################


				$j++;
			}
			
			//update scenario
			$oldscen = get_option($scen);
			update_option($scen, array($oldscen[0]+$somethingclicked, $oldscen[1]+(1-$somethingclicked)*$probnoneinteresting));
			$datum = processrawdata();
		}
		
	}
	
	
	function bttl_control()
	{
		$options = get_option('bttl_control');
		$newoptions = $options;
		
		if (!is_array($options) )
		{
			 add_option('bttl_control', array('title'=>'Featured', 'reset'=>'0', 'count'=>'4', 'showscore'=>'0'));
			 $options = get_option('bttl_control');
			 $newoptions = $options;
		}
		
		
		if ($_POST['bttl-submit'] )
		{
			 $newoptions['title'] = strip_tags(stripslashes($_POST['bttl-title']));
			 $newoptions['reset'] = (int) $_POST['bttl-reset'];
			 $newoptions['count'] = (int) $_POST['bttl-count'];
			 $newoptions['showscore'] = (int) $_POST['bttl-showscore'];
		}
		
		
		if ($options != $newoptions )
		{
			 $options = $newoptions;
			 update_option('bttl_control', $options);
		}
		
		
		?><div style="text-align:right"> <label for="bttl-title" style="line-height:35px;display:block;"><?php
		_e('Widget title:', 'widgets');
		?><input type="text" id="bttl-title" name="bttl-title" value="<?php
		echo wp_specialchars($options['title'], true);
		?>" /></label> <label for="bttl-count" style="line-height:35px;display:block;"><?php
		_e('Number of links:', 'widgets');
		?><input type="text" id="bttl-count" name="bttl-count" value="<?php
		echo $options['count'];
		?>" /></label> <input type="hidden" name="bttl-submit" id="bttl-submit" value="1" /> <label for="bttl-reset" style="line-height:35px;display:block;"><?php
		_e('Reset, 0 or 1:', 'widgets');
		?><input type="text" id="bttl-reset" name="bttl-reset" value="<?php
		echo $options['reset'];
		?>" /></label> <label for="bttl-showscore" style="line-height:35px;display:block;"><?php
		_e('Show scores, 0 or 1:', 'widgets');
		?><input type="text" id="bttl-showscore" name="bttl-showscore" value="<?php
		echo $options['showscore'];
		?>" /></label> <input type="hidden" name="bttl-submit" id="bttl-submit" value="1" /> </div><?php
	}
	
	
	function init_bttl()
	{
		register_sidebar_widget(array('Veracity', 'widgets'), 'bttl_display');
	}
	
	// This registers our widget so it appears with the other available
	register_sidebar_widget(array('Veracity', 'widgets'), 'bttl_display');
	register_widget_control(array('Veracity', 'widgets'), 'bttl_control', 300, 100);
}




//First check how we got here, either record a link, or put up your hooks

if (isset($_GET['stamp']))
{
	//RECORD LINKS
	 $timestamp = $_GET['stamp'];
	 $items = $_GET['items'];
	 $clicked = $_GET['clicked'];


	 $dir_tries = 0;
	 $dir = dirname( __FILE__ );
	 while ( !file_exists( "$dir/wp-load.php" ) && $dir_tries < 5 ) {
        	$dir = dirname( $dir );
              	$dir_tries++;
	 }
         require_once( "$dir/wp-load.php" );
	 $table_name=$wpdb->prefix.'bttl_data';
	 $bob=$wpdb->update($table_name, array('clicked'=>1), array('timestamp'=>$timestamp, 'items'=>$items) ) ;
	 header('Location: '.rawurldecode($_GET['guid']));
}

else{
	add_action('widgets_init', 'bttl_widget');
}


/* popularity section. */

if (!defined('AKPC_LOADED')) : // LOADED CHECK

@define('AKPC_LOADED', true);

/* -- INSTALLATION --------------------- */

// To hide the popularity score on a per post/page basis, add a custom field to the post/page as follows:
//   name: hide_popularity
//   value: 1


// When this is set to 1, WPMU will auto-install popularity contest for each installed blog when installed in the mu-plugins folder

@define('AKPC_MU_AUTOINSTALL', 1);

// Change this to 1 if you want popularity contest to pull its config from this file instead of the database
// This option hides most of the Popularity Contest admin page

@define('AKPC_CONFIG_FILE', 0);

// By default the view is recorded via an Ajax call from the page. If you want Popularity Contest to do this on the
// back end set this to 0. Setting this to 0 will cause popularity contest results to improperly tally when caching is
// turned on. It is recommended to use the API.

@define('AKPC_USE_API', 1);


// if pulling settings from this file, set weight values below
$akpc_settings['show_pop'] = 1;		// clickthrough from feed
$akpc_settings['show_help'] = 1;		// clickthrough from feed
$akpc_settings['ignore_authors'] = 1;		// clickthrough from feed
$akpc_settings['feed_value'] = 1;		// clickthrough from feed
$akpc_settings['home_value'] = 2;		// clickthrough from home
$akpc_settings['archive_value'] = 4;	// clickthrough from archive page
$akpc_settings['category_value'] = 6;	// clickthrough from category page
$akpc_settings['single_value'] = 10;	// full article page view
$akpc_settings['comment_value'] = 20;	// comment on article
$akpc_settings['pingback_value'] = 50;	// pingback on article
$akpc_settings['trackback_value'] = 80;	// trackback on article
$akpc_settings['searcher_names'] = 'google.com yahoo.com bing.com'; // serach engine bot names, space separated


// If you would like to show lists of popular posts in the sidebar,
// take a look at how it is implemented in the included sidebar.php.

/* ------------------------------------- */

load_plugin_textdomain('popularity-contest');

if (is_file(trailingslashit(ABSPATH.PLUGINDIR).'popularity-contest.php')) {
	define('AKPC_FILE', trailingslashit(ABSPATH.PLUGINDIR).'popularity-contest.php');
}
else if (is_file(trailingslashit(ABSPATH.PLUGINDIR).'popularity-contest/popularity-contest.php')) {
	define('AKPC_FILE', trailingslashit(ABSPATH.PLUGINDIR).'popularity-contest/popularity-contest.php');
}

register_activation_hook(AKPC_FILE, 'akpc_install');

function akpc_install() {
	global $akpc;
	if (!is_a($akpc, 'ak_popularity_contest')) {
		$akpc = new ak_popularity_contest();
	}
	$akpc->install();
	$akpc->upgrade();
	$akpc->mine_gap_data();
}

// -- MAIN FUNCTIONALITY

class ak_popularity_contest {
	var $feed_value;
	var $home_value;
	var $archive_value;
	var $category_value;
	var $single_value;
	var $comment_value;
	var $pingback_value;
	var $trackback_value;
	var $searcher_names;
	var $logged;
	var $options;
	var $top_ranked;
	var $current_posts;
	var $show_pop;
	var $show_help;
	var $ignore_authors;

	var $report_types;

	function ak_popularity_contest() {
		$this->options = array(
			'feed_value'
			,'home_value'
			,'archive_value'
			,'category_value'
			,'tag_value'
			,'single_value'
			,'searcher_value'
			,'comment_value'
			,'pingback_value'
			,'trackback_value'
			,'searcher_names'
			,'show_pop'
			,'show_help'
			,'ignore_authors'
		);
		$this->feed_value = 1;
		$this->home_value = 2;
		$this->archive_value = 4;
		$this->category_value = 6;
		$this->tag_value = 6;
		$this->single_value = 10;
		$this->searcher_value = 2;
		$this->comment_value = 20;
		$this->pingback_value = 50;
		$this->trackback_value = 80;
		$this->searcher_names = 'google.com yahoo.com bing.com';
		$this->logged = 0;
		$this->show_pop = 1;
		$this->show_help = 1;
		$this->ignore_authors = 1;
		$this->top_ranked = array();
		$this->current_posts = array();
	}

	function get_settings() {
		global $wpdb;
		if (AKPC_CONFIG_FILE == 1) { // use hard coded settings
			global $akpc_settings;
			foreach($akpc_settings as $key => $value) {
				if (in_array($key, $this->options)) {
					$this->$key = $value;
				}
			}
		}
		else { // pull settings from db
			// This checks to see if the tables are in the DB for this blog
			$settings = $this->query_settings();

			// If the DB tables are not in place, lets check to see if we can install
			if (!count($settings)) {
				// This checks to see if we need to install, then checks if we can install
				// For the can install to work in MU the AKPC_MU_AUTOINSTALL variable must be set to 1
				if (!$this->check_install() && $this->can_autoinstall()) {
					$this->install();
				}
				if (!$this->check_install()) {
					$error = __('
<h2>Popularity Contest Installation Failed</h2>
<p>Sorry, Popularity Contest was not successfully installed. Please try again, or try one of the following options for support:</p>
<ul>
	<li><a href="http://wphelpcenter.com">WordPress HelpCenter</a> (the official support provider for Popularity Contest)</li>
	<li><a href="http://wordpress.org">WordPress Forums</a> (community support forums)</li>
</ul>
<p>If you are having trouble and need to disable Popularity Contest immediately, simply delete the popularity-contest.php file from within your wp-content/plugins directory.</p>
					', 'popularity-contest');
					wp_die($error);
				}
				else {
					$settings = $this->query_settings();
				}
			}
			if (count($settings)) {
				foreach ($settings as $setting) {
					if (in_array($setting->option_name, $this->options)) {
						$this->{$setting->option_name} = $setting->option_value;
					}
				}
			}
		}
		return true;
	}

	function query_settings() {
		global $wpdb;
		return @$wpdb->get_results("
			SELECT *
			FROM $wpdb->ak_popularity_options
		");
	}

	/**
	 * check_install - This function checks to see if the proper tables have been added to the DB for the blog the plugin is being activated for
	 *
	 * @return void
	 */
	function check_install() {
		global $wpdb;
		$result = mysql_query("SHOW TABLES LIKE '{$wpdb->prefix}ak_popularity%'", $wpdb->dbh);
		return mysql_num_rows($result) == 2;
	}

	/**
	 * can_autoinstall - This function checks to see whether the tables can be installed
	 *
	 * @return void - Checks to see if the blog is MU, if not returns true
	 * 				- Checks to see if the blog is MU, if it is also checks to see if the function can install and returns true if it can
	 * 				- (For the second condition to work: ie. if the plugin is installed in MU: AKPC_MU_AUTOINSTALL must be set to 1)
	 */
	function can_autoinstall() {
		global $wpmu_version;
		return (is_null($wpmu_version) || (!is_null($wpmu_version) && AKPC_MU_AUTOINSTALL == 1));
	}

	/**
	 * install - This function installs the proper tables in the DB for handling popularity contest items
	 *
	 * @return void - Returns whether the table creation was successful
	 */
	function install() {
		global $wpdb;
		if ($this->check_install()) {
			return;
		}
		$result = mysql_query("
			CREATE TABLE `$wpdb->ak_popularity_options` (
				`option_name` VARCHAR( 50 ) NOT NULL,
				`option_value` VARCHAR( 50 ) NOT NULL
			)
		", $wpdb->dbh) or die(mysql_error().' on line: '.__LINE__);
		if (!$result) {
			return false;
		}

		$this->default_values();

		$result = mysql_query("
			CREATE TABLE `$wpdb->ak_popularity` (
				`post_id` INT( 11 ) NOT NULL ,
				`total` INT( 11 ) NOT NULL ,
				`feed_views` INT( 11 ) NOT NULL ,
				`home_views` INT( 11 ) NOT NULL ,
				`archive_views` INT( 11 ) NOT NULL ,
				`category_views` INT( 11 ) NOT NULL ,
				`tag_views` INT( 11 ) NOT NULL ,
				`single_views` INT( 11 ) NOT NULL ,
				`searcher_views` INT( 11 ) NOT NULL ,
				`comments` INT( 11 ) NOT NULL ,
				`pingbacks` INT( 11 ) NOT NULL ,
				`trackbacks` INT( 11 ) NOT NULL ,
				`last_modified` DATETIME NOT NULL ,
				KEY `post_id` ( `post_id` )
			)
		", $wpdb->dbh) or die(mysql_error().' on line: '.__LINE__);
		if (!$result) {
			return false;
		}

		$this->mine_data();

		return true;
	}

	function upgrade() {
		$this->upgrade_20();
	}

	function upgrade_20() {
		global $wpdb;

		$cols = $wpdb->get_col("
			SHOW COLUMNS FROM $wpdb->ak_popularity
		");

		//2.0 Schema
		if (!in_array('tag_views', $cols)) {
			$wpdb->query("
				ALTER TABLE `$wpdb->ak_popularity`
				ADD `tag_views` INT( 11 ) NOT NULL
				AFTER `category_views`
			");
		}
		if (!in_array('searcher_views', $cols)) {
			$wpdb->query("
				ALTER TABLE `$wpdb->ak_popularity`
				ADD `searcher_views` INT( 11 ) NOT NULL
				AFTER `single_views`
			");
		}
		$temp = new ak_popularity_contest;
		$cols = $wpdb->get_col("
			SELECT `option_name`
			FROM `$wpdb->ak_popularity_options`
		");
		if (!in_array('searcher_names', $cols)) {
			$wpdb->query("
				INSERT
				INTO `$wpdb->ak_popularity_options` (
					`option_name`,
					`option_value`
				)
				VALUES (
					'searcher_names',
					'$temp->searcher_names'
				)

			");
		}
		if (!in_array('show_pop', $cols)) {
			$wpdb->query("
				INSERT
				INTO `$wpdb->ak_popularity_options` (
					`option_name`,
					`option_value`
				)
				VALUES (
					'show_pop',
					'$temp->show_pop'
				)

			");
		}
		if (!in_array('show_help', $cols)) {
			$wpdb->query("
				INSERT
				INTO `$wpdb->ak_popularity_options` (
					`option_name`,
					`option_value`
				)
				VALUES (
					'show_help',
					'$temp->show_help'
				)

			");
		}
		if (!in_array('ignore_authors', $cols)) {
			$wpdb->query("
				INSERT
				INTO `$wpdb->ak_popularity_options` (
					`option_name`,
					`option_value`
				)
				VALUES (
					'ignore_authors',
					'$temp->ignore_authors'
				)

			");
		}
	}

	function default_values() {
		global $wpdb;
		foreach ($this->options as $option) {
			$result = $wpdb->query("
				INSERT
				INTO $wpdb->ak_popularity_options
				VALUES (
				'$option',
				'{$this->$option}'
				)
			");
			if (!$result) {
				return false;
			}
		}
		return true;
	}

	function update_settings() {
		if (!current_user_can('manage_options')) { wp_die('Unauthorized.'); }
		global $wpdb;
		$this->upgrade();
		foreach ($this->options as $option) {
			if (isset($_POST[$option])) {
				$option != 'searcher_names' ? $this->$option = intval($_POST[$option]) : $this->$option = stripslashes($_POST[$option]);
				$wpdb->query("
					UPDATE $wpdb->ak_popularity_options
					SET option_value = '{$this->$option}'
					WHERE option_name = '".$wpdb->escape($option)."'
				");
			}
		}
		$this->recalculate_popularity();
		$this->mine_gap_data();
		header('Location: '.get_bloginfo('wpurl').'/wp-admin/options-general.php?page='.basename(__FILE__).'&updated=true');
		die();
	}

	function recalculate_popularity() {
		global $wpdb;
		$result = $wpdb->query("
			UPDATE $wpdb->ak_popularity
			SET total = (home_views * $this->home_value)
				+ (feed_views * $this->feed_value)
				+ (archive_views * $this->archive_value)
				+ (category_views * $this->category_value)
				+ (tag_views * $this->tag_value)
				+ (single_views * $this->single_value)
				+ (searcher_views * $this->searcher_value)
				+ (comments * $this->comment_value)
				+ (pingbacks * $this->pingback_value)
				+ (trackbacks * $this->trackback_value)
		");
	}

	function reset_data() {
		global $wpdb;
		$result = $wpdb->query("
			TRUNCATE $wpdb->ak_popularity
		");
		if (!$result) {
			return false;
		}

		$result = $wpdb->query("
			TRUNCATE $wpdb->ak_popularity_options
		");
		if (!$result) {
			return false;
		}

		$this->default_values();
		return true;
	}

	function create_post_record($post_id = -1) {
		global $wpdb;
		if ($post_id == -1) {
			global $post_id;
		}
		$post_id = intval($post_id);
		$count = $wpdb->get_var("
			SELECT COUNT(post_id)
			FROM $wpdb->ak_popularity
			WHERE post_id = '$post_id'
		");
		if (!intval($count)) {
			$result = $wpdb->query("
				INSERT
				INTO $wpdb->ak_popularity (
					`post_id`,
					`last_modified`
				)
				VALUES (
					'$post_id',
					'".date('Y-m-d H:i:s')."'
				)
			");
		}
	}

	function delete_post_record($post_id = -1) {
		global $wpdb;
		if ($post_id == -1) {
			global $post_id;
		}
		$result = $wpdb->query("
			DELETE
			FROM $wpdb->ak_popularity
			WHERE post_id = '$post_id'
		");

	}

	function mine_data() {
		global $wpdb;
		$posts = $wpdb->get_results("
			SELECT ID
			FROM $wpdb->posts
			WHERE post_status = 'publish'
		");
		if ($posts && count($posts) > 0) {
			foreach ($posts as $post) {
				$this->create_post_record($post->ID);
				$this->populate_post_data($post->ID);
			}
		}
		return true;
	}

	function mine_gap_data() {
		global $wpdb;
		$posts = $wpdb->get_results("
			SELECT p.ID
			FROM $wpdb->posts p
			LEFT JOIN $wpdb->ak_popularity pop
			ON p.ID = pop.post_id
			WHERE pop.post_id IS NULL
			AND (
				p.post_type = 'post'
				OR p.post_type = 'page'
			)
			AND p.post_status = 'publish'
		");
		if ($posts && count($posts) > 0) {
			foreach ($posts as $post) {
				$this->create_post_record($post->ID);
				$this->populate_post_data($post->ID);
			}
		}
	}

	function populate_post_data($post_id) {
		global $wpdb;
// grab existing comments
		$count = intval($wpdb->get_var("
			SELECT COUNT(*)
			FROM $wpdb->comments
			WHERE comment_post_ID = '$post_id'
			AND comment_type = ''
			AND comment_approved = '1'
		"));
		if ($count > 0) {
			$result = $wpdb->query("
				UPDATE $wpdb->ak_popularity
				SET comments = comments + $count
				, total = total + ".($this->comment_value * $count)."
				WHERE post_id = '$post_id'
			");
			if (!$result) {
				return false;
			}
		}

// grab existing trackbacks
		$count = intval($wpdb->get_var("
			SELECT COUNT(*)
			FROM $wpdb->comments
			WHERE comment_post_ID = '$post_id'
			AND comment_type = 'trackback'
			AND comment_approved = '1'
		"));
		if ($count > 0) {
			$result = $wpdb->query("
				UPDATE $wpdb->ak_popularity
				SET trackbacks = trackbacks + $count
				, total = total + ".($this->trackback_value * $count)."
				WHERE post_id = '$post_id'
			");
			if (!$result) {
				return false;
			}
		}

// grab existing pingbacks
		$count = intval($wpdb->get_var("
			SELECT COUNT(*)
			FROM $wpdb->comments
			WHERE comment_post_ID = '$post_id'
			AND comment_type = 'pingback'
			AND comment_approved = '1'
		"));
		if ($count > 0) {
			$result = $wpdb->query("
				UPDATE $wpdb->ak_popularity
				SET pingbacks = pingbacks + $count
				, total = total + ".($this->pingback_value * $count)."
				WHERE post_id = '$post_id'
			");
			if (!$result) {
				return false;
			}
		}
	}

	function record_view($api = false, $ids = false, $type = false) {
		if ($this->logged > 0 || ($this->ignore_authors && current_user_can('publish_posts'))) {
			return true;
		}

		global $wpdb;

		if ($api == false) {
			global $posts;

			if (!isset($posts) || !is_array($posts) || count($posts) == 0 || is_admin()) {
				return;
			}

			$ids = array();
			$ak_posts = $posts;
			foreach ($ak_posts as $post) {
				$ids[] = $post->ID;
			}
		}
		if (!$ids || !count($ids)) {
			return;
		}
		if (($api && $type == 'feed') || is_feed()) {
			$result = $wpdb->query("
				UPDATE $wpdb->ak_popularity
				SET feed_views = feed_views + 1
				, total = total + $this->feed_value
				WHERE post_id IN (".implode(',', $ids).")
			");
			if (!$result) {
				return false;
			}
		}
		else if (($api && $type == 'archive') || (is_archive() && !is_category())) {
			$result = $wpdb->query("
				UPDATE $wpdb->ak_popularity
				SET archive_views = archive_views + 1
				, total = total + $this->archive_value
				WHERE post_id IN (".implode(',', $ids).")
			");
			if (!$result) {
				return false;
			}
		}
		else if (($api && $type == 'category') || is_category()) {
			$result = $wpdb->query("
				UPDATE $wpdb->ak_popularity
				SET category_views = category_views + 1
				, total = total + $this->category_value
				WHERE post_id IN (".implode(',', $ids).")
			");
			if (!$result) {
				return false;
			}
		}
		else if (($api && $type == 'tag') || is_tag()) {
			$result = $wpdb->query("
				UPDATE $wpdb->ak_popularity
				SET tag_views = tag_views + 1
				, total = total + $this->tag_views
				WHERE post_id IN (".implode(',', $ids).")
			");
			if (!$result) {
				return false;
			}
		}
		else if (($api && in_array($type, array('single', 'page'))) || is_single() || is_singular() || is_page()) {
			if (($api && $type == 'searcher') || akpc_is_searcher()) {
				$result = $wpdb->query("
					UPDATE $wpdb->ak_popularity
					SET searcher_views = searcher_views + 1
					, total = total + $this->searcher_value
					WHERE post_id = '".$ids[0]."'
				");
				if (!$result) {
					return false;
				}
			}
			$result = $wpdb->query("
				UPDATE $wpdb->ak_popularity
				SET single_views = single_views + 1
				, total = total + $this->single_value
				WHERE post_id = '".$ids[0]."'
			");
			if (!$result) {
				return false;
			}
		}
		else {
			$result = $wpdb->query("
				UPDATE $wpdb->ak_popularity
				SET home_views = home_views + 1
				, total = total + $this->home_value
				WHERE post_id IN (".implode(',', $ids).")
			");
			if (!$result) {
				return false;
			}
		}
		$this->logged++;
		return true;
	}

	function record_feedback($type, $action = '+', $comment_id = null) {
		global $wpdb, $comment_post_ID;
		if ($comment_id) {
			$comment_post_ID = $comment_id;
		}
		switch ($type) {
			case 'trackback':
				$result = $wpdb->query("
					UPDATE $wpdb->ak_popularity
					SET trackbacks = trackbacks $action 1
					, total = total $action $this->trackback_value
					WHERE post_id = '$comment_post_ID'
				");
				if (!$result) {
					return false;
				}
				break;
			case 'pingback':
				$result = $wpdb->query("
					UPDATE $wpdb->ak_popularity
					SET pingbacks = pingbacks $action 1
					, total = total $action $this->pingback_value
					WHERE post_id = '$comment_post_ID'
				");
				if (!$result) {
					return false;
				}
				break;
			default:
				$result = $wpdb->query("
					UPDATE $wpdb->ak_popularity
					SET comments = comments $action 1
					, total = total $action $this->comment_value
					WHERE post_id = '$comment_post_ID'
				");
				if (!$result) {
					return false;
				}
				break;
		}
		return true;
	}

	function edit_feedback($comment_id, $action, $status = null) {
		$comment = get_comment($comment_id);
		switch ($action) {
			case 'delete':
				$this->record_feedback($comment->comment_type, '-', $comment_id);
				break;
			case 'status':
				if ($status == 'spam') {
					$this->record_feedback($comment->comment_type, '-', $comment_id);
					return;
				}
				break;
		}
	}

	function recount_feedback() {
		global $wpdb;
		$post_ids = $wpdb->get_results("
			SELECT ID
			FROM $wpdb->posts
			WHERE post_status = 'publish'
			OR post_status = 'static'
		");

		if (count($post_ids)) {
			$result = $wpdb->query("
				UPDATE $wpdb->ak_popularity
				SET comments = 0
				, trackbacks = 0
				, pingbacks = 0
			");
			foreach ($post_ids as $post_id) {
				$this->populate_post_data($post_id);
			}
		}
		$this->recalculate_popularity();

		header('Location: '.get_bloginfo('wpurl').'/wp-admin/options-general.php?page='.basename(__FILE__).'&updated=true');
		die();
	}

	function options_form() {
		if (!AKPC_CONFIG_FILE) { // don't show options update functions if we're running from a config file
			$temp = new ak_popularity_contest;
			print('<div class="wrap">');
			$yes_no = array(
				'show_pop',
				'show_help',
				'ignore_authors',
			);
			foreach ($yes_no as $key) {
				$var = $key.'_options';
				if ($this->$key == '0') {
					$$var = '
						<option value="1">'.__('Yes', 'popularity-contest').'</option>
						<option value="0" selected="selected">'.__('No', 'popularity-contest').'</option>
					';
				}
				else {
					$$var = '
						<option value="1" selected="selected">'.__('Yes', 'popularity-contest').'</option>
						<option value="0">'.__('No', 'popularity-contest').'</option>
					';
				}
			}

			print('
					<h2>'.__('Popularity Contest Options', 'popularity-contest').'</h2>
					<form name="ak_popularity" action="'.get_bloginfo('wpurl').'/wp-admin/options-general.php" method="post">
						<fieldset class="options">
							<h3>'.__('Settings', 'popularity-contest').'</h3>
							<p>
								<label for="akpc_ignore_authors">'.__('Ignore views by site authors:', 'popularity-contest').'</label>
								<select name="ignore_authors" id="akpc_ignore_authors">
								'.$ignore_authors_options.'
								</select>
							</p>
							<p>
								<label for="akpc_show_pop">'.__('Show popularity rank for posts:', 'popularity-contest').'</label>
								<select name="show_pop" id="akpc_show_pop">
								'.$show_pop_options.'
								</select>
							</p>
							<p>
								<label for="akpc_show_help">'.__('Show the [?] help link:', 'popularity-contest').'</label>
								<select name="show_help" id="akpc_show_help">
								'.$show_help_options.'
								</select>
							</p>
							<p>
								<label>'.__('Search Engine Domains (space separated):', 'popularity-contest').'</label><br/>
								<textarea name="searcher_names" id="searcher_names" rows="2" cols="50">'.htmlspecialchars($this->searcher_names).'</textarea>
							</p>
						</fieldset>
						<fieldset class="options">
							<h3>'.__('Popularity Values', 'popularity-contest').'</h3>
							<p>'.__('Adjust the values below as you see fit. When you save the new options the <a href="index.php?page=popularity-contest.php"><strong>popularity rankings</strong></a> for your posts will be automatically updated to reflect the new values you have chosen.', 'popularity-contest').'</p>
							<table width="100%" cellspacing="2" cellpadding="5" class="editform" id="akpc_options">
								<tr valign="top">
									<th width="33%" scope="row"><label for="single_value">'.__('Permalink Views:', 'popularity-contest').'</label></th>
									<td><input type="text" class="number" name="single_value" id="single_value" value="'.$this->single_value.'" /> '.__("(default: $temp->single_value)", 'popularity-contest').'</td>
								</tr>
								<tr valign="top">
									<th width="33%" scope="row"><label for="searcher_value">'.__('Permalink Views from Search Engines:', 'popularity-contest').'</label></th>
									<td><input type="text" class="number" name="searcher_value" id="searcher_value" value="'.$this->searcher_value.'" /> '.__("(default: $temp->searcher_value)", 'popularity-contest').'</td>
								</tr>
								<tr valign="top">
									<th width="33%" scope="row"><label for="home_value">'.__('Home Views:', 'popularity-contest').'</label></th>
									<td><input type="text" class="number" name="home_value" id="home_value" value="'.$this->home_value.'" /> '.__("(default: $temp->home_value)", 'popularity-contest').'</td>
								</tr>
								<tr valign="top">
									<th width="33%" scope="row"><label for="archive_value">'.__('Archive Views:', 'popularity-contest').'</label></th>
									<td><input type="text" class="number" name="archive_value" id="archive_value" value="'.$this->archive_value.'" /> '.__("(default: $temp->archive_value)", 'popularity-contest').'</td>
								</tr>
								<tr valign="top">
									<th width="33%" scope="row"><label for="category_value">'.__('Category Views:', 'popularity-contest').'</label></th>
									<td><input type="text" class="number" name="category_value" id="category_value" value="'.$this->category_value.'" /> '.__("(default: $temp->category_value)", 'popularity-contest').'</td>
								</tr>
								<tr valign="top">
									<th width="33%" scope="row"><label for="tag_value">'.__('Tag Views:', 'popularity-contest').'</label></th>
									<td><input type="text" class="number" name="tag_value" id="tag_value" value="'.$this->tag_value.'" /> '.__("(default: $temp->tag_value)", 'popularity-contest').'</td>
								</tr>
								<tr valign="top">
									<th width="33%" scope="row"><label for="feed_value">'.__('Feed Views (full content only):', 'popularity-contest').'</label></th>
									<td><input type="text" class="number" name="feed_value" id="feed_value" value="'.$this->feed_value.'" /> '.__("(default: $temp->feed_value)", 'popularity-contest').'</td>
								</tr>
								<tr valign="top">
									<th width="33%" scope="row"><label for="comment_value">'.__('Comments:', 'popularity-contest').'</label></th>
									<td><input type="text" class="number" name="comment_value" id="comment_value" value="'.$this->comment_value.'" /> '.__("(default: $temp->comment_value)", 'popularity-contest').'</td>
								</tr>
								<tr valign="top">
									<th width="33%" scope="row"><label for="pingback_value">'.__('Pingbacks:', 'popularity-contest').'</label></th>
									<td><input type="text" class="number" name="pingback_value" id="pingback_value" value="'.$this->pingback_value.'" /> '.__("(default: $temp->pingback_value)", 'popularity-contest').'</td>
								</tr>
								<tr valign="top">
									<th width="33%" scope="row"><label for="trackback_value">'.__('Trackbacks:', 'popularity-contest').'</label></th>
									<td><input type="text" class="number" name="trackback_value" id="trackback_value" value="'.$this->trackback_value.'" /> '.__("(default: $temp->trackback_value)", 'popularity-contest').'</td>
								</tr>
							</table>
							<h3>'.__('Example', 'popularity-contest').'</h3>
							<ul>
								<li>'.__('Post #1 receives 11 Home Page Views (11 * 2 = 22), 6 Permalink Views (6 * 10 = 60) and 3 Comments (3 * 20 = 60) for a total value of: <strong>142</strong>', 'popularity-contest').'</li>
								<li>'.__('Post #2 receives 7 Home Page Views (7 * 2 = 14), 10 Permalink Views (10 * 10 = 100), 7 Comments (7 * 20 = 140) and 3 Trackbacks (3 * 80 = 240) for a total value of: <strong>494</strong>', 'popularity-contest').'</li>
							</ul>
							<hr style="margin: 20px 40px; border: 0; border-top: 1px solid #ccc;" />
							<input type="hidden" name="ak_action" value="update_popularity_values" />
						</fieldset>
						<p class="submit">
							<input type="submit" name="submit" value="'.__('Save Popularity Contest Options', 'popularity-contest').'" class="button-primary" />
							<input type="button" name="recount" value="'.__('Reset Comments/Trackback/Pingback Counts', 'popularity-contest').'" onclick="location.href=\''.get_bloginfo('wpurl').'/wp-admin/options-general.php?ak_action=recount_feedback\';" />
						</p>
					</form>
					');
		}
		print('
				<div id="akpc_template_tags">
					<h2>'.__('Popularity Contest Template Tags', 'popularity-contest').'</h2>
					<dl>
						<dt><code>akpc_the_popularity()</code></dt>
						<dd>
							<p>'.__('Put this tag within <a href="http://codex.wordpress.org/The_Loop">The Loop</a> to show the popularity of the post being shown. The popularity is shown as a percentage of your most popular post. For example, if the popularity total for Post #1 is 500 and your popular post has a total of 1000, this tag will show a value of <strong>50%</strong>.', 'popularity-contest').'</p>
							<p>Example:</p>
							<ul>
								<li><code>&lt;?php if (function_exists(\'akpc_the_popularity\')) { akpc_the_popularity(); } ?></code></li>
							</ul>
						</dd>
						<dt><code>akpc_most_popular($limit = 10, $before = &lt;li>, $after = &lt;/li>)</code></dt>
						<dd>
							<p>'.__('Put this tag outside of <a href="http://codex.wordpress.org/The_Loop">The Loop</a> (perhaps in your sidebar?) to show a list (like the archives/categories/links list) of your most popular posts. All arguments are optional, the defaults are included in the example above.', 'popularity-contest').'</p>
							<p>Examples:</p>
							<ul>
								<li><code>&lt;?php if (function_exists(\'akpc_most_popular\')) { akpc_most_popular(); } ?></code></li>
								<li><code>
									&lt;?php if (function_exists(\'akpc_most_popular\')) { ?><br />
									&lt;li>&lt;h2>Most Popular Posts&lt;/h2><br />
									&nbsp;&nbsp;	&lt;ul><br />
									&nbsp;&nbsp;	&lt;?php akpc_most_popular(); ?><br />
									&nbsp;&nbsp;	&lt;/ul><br />
									&lt;/li><br />
									&lt;?php } ?>
								</code></li>
							</ul>
						</dd>
						<dt><code>akpc_most_popular_in_cat($limit = 10, $before = &lt;li>, $after = &lt;/li>, $cat_ID = current category)</code></dt>
						<dd>
							<p>'.__('Put this tag outside of <a href="http://codex.wordpress.org/The_Loop">The Loop</a> (perhaps in your sidebar?) to show a list of the most popular posts in a specific category. You may want to use this on category archive pages. All arguments are', 'popularity-contest').'</p>
							<p>Examples:</p>
							<ul>
								<li><code>&lt;?php if (function_exists(\'akpc_most_popular_in_cat\')) { akpc_most_popular_in_cat(); } ?></code></li>
								<li><code>&lt;php if (is_category() && function_exists(\'akpc_most_popular_in_cat\')) { akpc_most_popular_in_cat(); } ?></code></li>
								<li><code>
									&lt;?php if (is_category() && function_exists(\'akpc_most_popular_in_cat\')) { ?><br />
									&lt;li>&lt;h2>Most Popular in \'&lt;?php single_cat_title(); ?>\'&lt;/h2><br />
									&nbsp;&nbsp;	&lt;ul><br />
									&nbsp;&nbsp;	&lt;?php akpc_most_popular_in_cat(); ?><br />
									&nbsp;&nbsp;	&lt;/ul><br />
									&lt;/li><br />
									&lt;?php } ?>
								</code></li>
							</ul>
						</dd>
						<dt><code>akpc_most_popular_in_month($limit, $before, $after, $m = YYYYMM)</code></dt>
						<dd>
							<p>'.__('Put this tag outside of <a href="http://codex.wordpress.org/The_Loop">The Loop</a> (perhaps in your sidebar?) to show a list of the most popular posts in a specific month. You may want to use this on monthly archive pages.', 'popularity-contest').'</p>
							<p>Examples:</p>
							<ul>
								<li><code>&lt;?php if (function_exists(\'akpc_most_popular_in_month\')) { akpc_most_popular_in_month(); } ?></code></li>
								<li><code>&lt;php if (is_archive() && is_month() && function_exists(\'akpc_most_popular_in_month\')) { akpc_most_popular_in_month(); } ?></code></li>
								<li><code>
									&lt;?php if (is_archive() && is_month() && function_exists(\'akpc_most_popular_in_month\')) { ?><br />
									&lt;li>&lt;h2>Most Popular in &lt;?php the_time(\'F, Y\'); ?>&lt;/h2><br />
									&nbsp;&nbsp;	&lt;ul><br />
									&nbsp;&nbsp;	&lt;?php akpc_most_popular_in_month(); ?><br />
									&nbsp;&nbsp;	&lt;/ul><br />
									&lt;/li><br />
									&lt;?php } ?>
								</code></li>
							</ul>
						</dd>
						<dt><code>akpc_most_popular_in_last_days($limit, $before, $after, $days = 45)</code></dt>
						<dd>
							<p>'.__('Put this tag outside of <a href="http://codex.wordpress.org/The_Loop">The Loop</a> (perhaps in your sidebar?) to show a list of the most popular posts in the last (your chosen number, default = 45) days.', 'popularity-contest').'</p>
							<p>Examples:</p>
							<ul>

								<li><code>&lt;?php if (function_exists(\'akpc_most_popular_in_last_days\')) { akpc_most_popular_in_last_days(); } ?></code></li>
								<li><code>
									&lt;?php if (function_exists(\'akpc_most_popular_in_last_days\')) { ?><br />
									&lt;li>&lt;h2>Recent Popular Posts&lt;/h2><br />
									&nbsp;&nbsp;	&lt;ul><br />
									&nbsp;&nbsp;	&lt;?php akpc_most_popular_in_last_days(); ?><br />
									&nbsp;&nbsp;	&lt;/ul><br />
									&lt;/li><br />
									&lt;?php } ?>
								</code></li>
							</ul>
						</dd>
					</dl>
				</div>
			</div>
		');
	}

	function get_popular_posts($type = 'popular', $limit, $exclude_pages = 'yes', $custom = array()) {
		global $wpdb;
		$items = array();
		switch($type) {
			case 'category':
				$temp = "
					SELECT p.ID AS ID, p.post_title AS post_title, pop.total AS total
					FROM $wpdb->posts p
					LEFT JOIN $wpdb->ak_popularity pop
					ON p.ID = pop.post_id
					LEFT JOIN $wpdb->term_relationships tr
					ON p.ID = tr.object_id
					LEFT JOIN $wpdb->term_taxonomy tt
					ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tt.term_id = ".$custom['cat_ID']."
					AND p.post_status = 'publish'
				";
				if ($exclude_pages == 'yes') { $temp .= " AND p.post_type != 'page' "; }
				$temp .= "
					ORDER BY pop.total DESC
					LIMIT $limit
				";
				$items = $wpdb->get_results($temp);
				break;
			case 'tag':
				$temp = "
					SELECT p.ID AS ID, p.post_title AS post_title, pop.total AS total
					FROM $wpdb->posts p
					LEFT JOIN $wpdb->ak_popularity pop
					ON p.ID = pop.post_id
					LEFT JOIN $wpdb->term_relationships tr
					ON p.ID = tr.object_id
					LEFT JOIN $wpdb->term_taxonomy tt
					ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tt.term_id = ".$custom['term_id']."
					AND p.post_status = 'publish'
				";
				if ($exclude_pages == 'yes') { $temp .= " AND p.post_type != 'page' "; }
				$temp .= "
					ORDER BY pop.total DESC
					LIMIT $limit
				";
				$items = $wpdb->get_results($temp);
				break;
			case 'category_popularity':
				$temp = "
					SELECT DISTINCT name, AVG(pop.total) AS avg
					FROM $wpdb->posts p
					LEFT JOIN $wpdb->ak_popularity pop
					ON p.ID = pop.post_id
					LEFT JOIN $wpdb->term_relationships tr
					ON p.ID = tr.object_id
					LEFT JOIN $wpdb->term_taxonomy tt
					ON tr.term_taxonomy_id = tt.term_taxonomy_id
					LEFT JOIN $wpdb->terms t
					ON tt.term_id = t.term_id
					WHERE tt.taxonomy = 'category'
				";
				if ($exclude_pages == 'yes') { $temp .= " AND p.post_type != 'page' "; }
				$temp .= "
					GROUP BY name
					ORDER BY avg DESC
					LIMIT 50
				";
				$items = $wpdb->get_results($temp);
				break;
			case 'tag_popularity':
				$temp = "
					SELECT DISTINCT name, AVG(pop.total) AS avg
					FROM $wpdb->posts p
					LEFT JOIN $wpdb->ak_popularity pop
					ON p.ID = pop.post_id
					LEFT JOIN $wpdb->term_relationships tr
					ON p.ID = tr.object_id
					LEFT JOIN $wpdb->term_taxonomy tt
					ON tr.term_taxonomy_id = tt.term_taxonomy_id
					LEFT JOIN $wpdb->terms t
					ON tt.term_id = t.term_id
					WHERE tt.taxonomy = 'post_tag'
				";
				if ($exclude_pages == 'yes') { $temp .= " AND p.post_type != 'page' "; }
				$temp .= "
					GROUP BY name
					ORDER BY avg DESC
					LIMIT 50
				";
				$items = $wpdb->get_results($temp);
				break;
			case 'year':
				$temp = "
					SELECT MONTH(p.post_date) AS month, AVG(pop.total) AS avg
					FROM $wpdb->posts p
					LEFT JOIN $wpdb->ak_popularity pop
					ON p.ID = pop.post_id
					WHERE YEAR(p.post_date) = '".$custom['y']."'
					AND p.post_status = 'publish'
				";
				if ($exclude_pages == 'yes') { $temp .= " AND p.post_type != 'page' "; }
				$temp .= "
					GROUP BY month
					ORDER BY avg DESC
				";
				$items = $wpdb->get_results($temp);
				break;
			case 'views_wo_feedback':
				$temp = "
					SELECT p.ID AS ID, p.post_title AS post_title, pop.total AS total
					FROM $wpdb->posts p
					LEFT JOIN $wpdb->ak_popularity pop
					ON p.ID = pop.post_id
					WHERE pop.comments = 0
					AND pop.pingbacks = 0
					AND pop.trackbacks = 0
					AND p.post_status = 'publish'
				";
				if ($exclude_pages == 'yes') { $temp .= " AND p.post_type != 'page' "; }
				$temp .= "
					ORDER BY pop.total DESC
					LIMIT $limit
				";
				$items = $wpdb->get_results($temp);
				break;
			case 'most_feedback':
				// in progress, should probably be combination of comment, pingback & trackback scores
				$temp = "
					SELECT p.ID, p.post_title, p.comment_count
					FROM $wpdb->posts p
					LEFT JOIN $wpdb->ak_popularity pop ON p.ID = pop.post_id
					WHERE p.post_status = 'publish'
					AND p.comment_count > 0";
				if ($exclude_pages == 'yes') { $temp .= " AND p.post_type != 'page' "; }
				$temp = "
					ORDER BY p.comment_count DESC
					LIMIT $limit;
				";
				$items = $wpdb->get_results($temp);
				break;
			case 'date':
				$temp = "
					SELECT p.ID AS ID, p.post_title AS post_title, pop.total AS total
					FROM $wpdb->posts p
					LEFT JOIN $wpdb->ak_popularity pop
					ON p.ID = pop.post_id
					WHERE DATE_ADD(p.post_date, INTERVAL ".intval($custom['days'])." DAY) {$custom['compare']} DATE_ADD(NOW(), INTERVAL ".intval($custom['offset'])." DAY)
					AND p.post_status = 'publish'
				";
				if ($exclude_pages == 'yes') { $temp .= " AND p.post_type != 'page' "; }
				$temp .= "
					ORDER BY pop.total DESC
					LIMIT $limit
				";
				$items = $wpdb->get_results($temp);
				break;
			case 'most':
				$temp = "
					SELECT p.ID AS ID, p.post_title AS post_title, pop.{$custom['column']} AS {$custom['column']}
					FROM $wpdb->posts p
					LEFT JOIN $wpdb->ak_popularity pop
					ON p.ID = pop.post_id
					WHERE p.post_status = 'publish'
				";
				if ($exclude_pages == 'yes') { $temp .= " AND p.post_type != 'page' "; }
				$temp .= "
					ORDER BY pop.{$custom['column']} DESC
					LIMIT $limit
				";
				$items = $wpdb->get_results($temp);
				break;
			case 'popular':
				$temp = "
					SELECT p.ID AS ID, p.post_title AS post_title, pop.{$custom['column']} AS {$custom['column']}
					FROM $wpdb->posts p
					LEFT JOIN $wpdb->ak_popularity pop
					ON p.ID = pop.post_id
					WHERE p.post_status = 'publish'
				";
				if ($exclude_pages == 'yes') { $temp .= " AND p.post_type != 'page' "; }
				$temp .= "
					ORDER BY pop.{$custom['column']} DESC
					LIMIT $limit
				";
				$items = $wpdb->get_results($temp);
				break;
			case 'popular_pages':
				$temp = "
					SELECT p.ID AS ID, p.post_title AS post_title, pop.single_views AS single_views
					FROM $wpdb->posts p
					LEFT JOIN $wpdb->ak_popularity pop
					ON p.ID = pop.post_id
					WHERE p.post_status = 'publish'
					AND p.post_type = 'page'
					ORDER BY pop.single_views DESC
					LIMIT $limit
				";
				$items = $wpdb->get_results($temp);
				break;
		}

		do_action('akpc_get_popular_posts',$items);

		if (count($items)) {
			return $items;
		}
		return false;
	}

	/**
	 * Show a popularity report
	 * @var string $type - type of report to show
	 * @var int $limit - num posts to show
	 * @var array $custom - pre-defined list of posts to show
	 * @var bool $hide_title - wether to echo the list title
	 */
	function show_report($type = 'popular', $limit = 10, $exclude_pages = 'yes', $custom = array(), $before_title = '<h3>', $after_title = '</h3>', $hide_title = false) {
		global $wpdb;

		if (count($custom) > 0 && 1 == 0) {
		}
		else {
			$query = '';
			$column = '';
			$list = '';
			$items = array();
			$rel = '';
			switch ($type) {
				case 'category':
					$title = $custom['cat_name'];
					$items = $this->get_popular_posts($type, $limit, $exclude_pages, $custom);
					$list = $this->report_list_items($items, $before = '<li>', $after = '</li>');
					break;
				case 'tag':
					$title = $custom['term_name'];
					$rel = sanitize_title($title);
					$items = $this->get_popular_posts($type, $limit, $exclude_pages, $custom);
					$list = $this->report_list_items($items, $before = '<li>', $after = '</li>');
					break;
				case 'pop_by_category':
					$cats = get_categories();
					if (count($cats)) {
						foreach ($cats as $cat) {
							$this->show_report('category', 10, $exclude_pages, array('cat_ID' => $cat->term_id, 'cat_name' => $cat->name));
						}
					}
					break;
				case 'pop_by_tag':
					$tags = maybe_unserialize(get_option('akpc_tag_reports'));
					if (is_array($tags) && count($tags)) {
						foreach ($tags as $tag) {
							$term = get_term_by('slug', $tag, 'post_tag');
							$this->show_report('tag', 10, $exclude_pages, array('term_id' => $term->term_id, 'term_name' => $term->name));
						}
					}
					break;
				case 'category_popularity':
					$title = __('Average by Category', 'popularity-contest');
					$items = $this->get_popular_posts($type, $limit, $exclude_pages);
					if (is_array($items) && count($items)) {
						foreach ($items as $item) {
							$list .= '	<li>
									<span>'.$this->get_rank(ceil($item->avg)).'</span>
									'.$item->name.'
								</li>'."\n";
						}
					}
					break;
				case 'tag_popularity':
					$title = __('Average by Tag', 'popularity-contest');
					$items = $this->get_popular_posts($type, $limit, $exclude_pages);
					if (is_array($items) && count($items)) {
						foreach ($items as $item) {
							$list .= '	<li>
									<span>'.$this->get_rank(ceil($item->avg)).'</span>
									'.$item->name.'
								</li>'."\n";
						}
					}
					break;
				case 'year':
					global $month;
					$title = $custom['y'].__(' Average by Month', 'popularity-contest');
					$items = $this->get_popular_posts($type,$limit, $exclude_pages,$custom);
					if (is_array($items) && count($items)) {
						foreach ($items as $item) {
							$list .= '	<li>
									<span>'.$this->get_rank(ceil($item->avg)).'</span>
									'.$month[str_pad($item->month, 2, '0', STR_PAD_LEFT)].'
								</li>'."\n";
						}
					}
					break;
				case 'month_popularity':
					$years = array();
					$years = $wpdb->get_results("
						SELECT DISTINCT YEAR(post_date) AS year
						FROM $wpdb->posts
						ORDER BY year DESC
					");
					$i = 2;
					if (count($years) > 0) {
						foreach ($years as $year) {
							$this->show_report('year', 10, $exclude_pages, array('y' => $year->year));
							if ($i == 3) {
								print('
										<div class="clear"></div>
								');
								$i = 0;
							}
							$i++;
						}
					}
					break;
				case 'views_wo_feedback':
					$title = __('Views w/o Feedback', 'popularity-contest');
					$items = $this->get_popular_posts($type, $limit, $exclude_pages);
					$list = $this->report_list_items($items, $before = '<li>', $after = '</li>');
					break;
				case 'most_feedback':
					$query = 'sum';
					$column = 'pop.comments + pop.pingbacks + pop.trackbacks AS feedback';
					$title = __('Feedback', 'popularity-contest');
					break;
				case '365_plus':
					$offset = -365;
					$compare = '<';
					$title = __('Older Than 1 Year', 'popularity-contest');
					$items = $this->get_popular_posts('date', $limit, $exclude_pages, array('days' => $days, 'offset' => $offset, 'compare' => $compare));
					$list = $this->report_list_items($items, $before = '<li>', $after = '</li>');
					break;
				case 'last_30':
				case 'last_60':
				case 'last_90':
				case 'last_365':
				case 'last_n':
					$compare = '>';
					$offset = $days = '0';
					switch(str_replace('last_','',$type)) {
						case '30':
							$days = 30;
							$title = __('Last 30 Days', 'popularity-contest');
							break;
						case '60':
							$days = 60;
							$title = __('Last 60 Days', 'popularity-contest');
							break;
						case '90':
							$days = 90;
							$title = __('Last 90 Days', 'popularity-contest');
							break;
						case '365':
							$days = 365;
							$title = __('Last Year', 'popularity-contest');
							break;
						case 'n':
							$days = $custom['days'];
							if ($days == 1) {
								$title = __('Last Day', 'popularity-contest');
							}
							else {
								$title = sprintf(__('Last %s Days', 'popularity-contest'), $days);
							}
							break;
					}
					$items = $this->get_popular_posts('date', $limit, $exclude_pages, array('days' => $days, 'offset' => $offset, 'compare' => $compare));
					$list = $this->report_list_items($items, $before = '<li>', $after = '</li>');
					break;
				case 'most_feed_views':
				case 'most_home_views':
				case 'most_archive_views':
				case 'most_category_views':
				case 'most_tag_views':
				case 'most_single_views':
				case 'most_searcher_views':
				case 'most_comments':
				case 'most_pingbacks':
				case 'most_trackbacks':
					switch($type) {
						case 'most_feed_views':
							$query = 'most';
							$column = 'feed_views';
							$title = __('Feed Views', 'popularity-contest');
							break;
						case 'most_home_views':
							$query = 'most';
							$column = 'home_views';
							$title = __('Home Page Views', 'popularity-contest');
							break;
						case 'most_archive_views':
							$query = 'most';
							$column = 'archive_views';
							$title = __('Archive Views', 'popularity-contest');
							break;
						case 'most_category_views':
							$query = 'most';
							$column = 'category_views';
							$title = __('Category Views', 'popularity-contest');
							break;
						case 'most_tag_views':
							$query = 'most';
							$column = 'tag_views';
							$title = __('Tag Views', 'popularity-contest');
							break;
						case 'most_single_views':
							$query = 'most';
							$column = 'single_views';
							$title = __('Single Post Views', 'popularity-contest');
							break;
						case 'most_searcher_views':
							$query = 'most';
							$column = 'searcher_views';
							$title = __('Search Engine Traffic', 'popularity-contest');
							break;
						case 'most_comments':
							$query = 'most';
							$column = 'comments';
							$title = __('Comments', 'popularity-contest');
							break;
						case 'most_pingbacks':
							$query = 'most';
							$column = 'pingbacks';
							$title = __('Pingbacks', 'popularity-contest');
							break;
						case 'most_trackbacks':
							$query = 'most';
							$column = 'trackbacks';
							$title = __('Trackbacks', 'popularity-contest');
							break;
					}
					$items = $this->get_popular_posts('most', $limit, $exclude_pages, array('column' => $column));
					if (is_array($items) && count($items)) {
						foreach ($items as $item) {
							$list .= '	<li>
									<span>'.$item->$column.'</span>
									<a href="'.get_permalink($item->ID).'">'.$item->post_title.'</a>
								</li>'."\n";
						}
					}
					else {
						$list = '<li>'.__('(none)', 'popularity-contest').'</li>';
					}
					break;
				case 'most_page_views':
					$column = 'single_views';
					$title = __('Page Views', 'popularity-contest');
					$items = $this->get_popular_posts('popular_pages', $limit, $exclude_pages, array('column' => $column));
					if (is_array($items) && count($items)) {
						foreach ($items as $item) {
							$list .= '	<li>
									<span>'.$item->$column.'</span>
									<a href="'.get_permalink($item->ID).'">'.$item->post_title.'</a>
								</li>'."\n";
						}
					}
					else {
						$list = '<li>'.__('(none)', 'popularity-contest').'</li>';
					}
					break;
				case 'popular':
					$query = 'popular';
					$column = 'total';
					$title = __('Most Popular', 'popularity-contest');
					$items = $this->get_popular_posts($type, $limit, $exclude_pages, array('column' => $column));
					if (is_array($items) && count($items)) {
						foreach ($items as $item) {
							$list .= '	<li>
									<span>'.$this->get_post_rank(null, $item->total).'</span>
									<a href="'.get_permalink($item->ID).'">'.$item->post_title.'</a>
								</li>'."\n";
						}
					}
					else {
						$list = '<li>'.__('(none)', 'popularity-contest').'</li>';
					}
					break;
			}
		}

		if (!empty($list)) {
			$html = '
			<div class="akpc_report" rel="'.$rel.'">
				'.($hide_title ? '' : $before_title.$title.$after_title).'
				<ol>
					'.$list.'
				</ol>
			</div>
			';
			echo apply_filters('akpc_show_report', $html, $items);
		}
	}

	/**
	 * create a list of popular items for a report
	 * @var array $items
	 * @return string - HTML
	 */
	function report_list_items($items, $before = '<li>', $after = '<li>') {
		if (!$items || !count($items)) { return false; }

		$html = '';
		foreach($items as $item) {
			$html .= $before.
					 '<span>'.$this->get_post_rank(null, $item->total).'</span><a href="'.get_permalink($item->ID).'">'.$item->post_title.'</a>'.
					 $after;
		}
		return $html;
	}

	function show_report_extended($type = 'popular', $limit = 50) {
		global $wpdb, $post;
		$columns = array(
			'popularity' => __('', 'popularity-contest')
			,'title'      => __('Title', 'popularity-contest')
			,'categories' => __('Categories', 'popularity-contest')
			,'single_views'     => __('Single', 'popularity-contest')
			,'searcher_views'     => __('Search', 'popularity-contest')
			,'category_views'     => __('Cat', 'popularity-contest')
			,'tag_views'     => __('Tag', 'popularity-contest')
			,'archive_views'     => __('Arch', 'popularity-contest')
			,'home_views'     => __('Home', 'popularity-contest')
			,'feed_views'     => __('Feed', 'popularity-contest')
			,'comments'     => __('Com', 'popularity-contest')
			,'pingbacks'     => __('Ping', 'popularity-contest')
			,'trackbacks'     => __('Track', 'popularity-contest')
		);
?>
<div id="akpc_most_popular">
	<table width="100%" cellpadding="3" cellspacing="2">
		<tr>
<?php
		foreach($columns as $column_display_name) {
?>
			<th scope="col"><?php echo $column_display_name; ?></th>
<?php
		}
?>
			</tr>
<?php
		$posts = $wpdb->get_results("
			SELECT p.*, pop.*
			FROM $wpdb->posts p
			LEFT JOIN $wpdb->ak_popularity pop
			ON p.ID = pop.post_id
			WHERE p.post_status = 'publish'
			ORDER BY pop.total DESC
			LIMIT ".intval($limit)
		);
		if ($posts) {
			$bgcolor = '';
			foreach ($posts as $post) {
				$class = ('alternate' == $class) ? '' : 'alternate';
?>
		<tr class='<?php echo $class; ?>'>
<?php
				foreach($columns as $column_name => $column_display_name) {
					switch($column_name) {
						case 'popularity':
?>
				<td class="right"><?php $this->show_post_rank(null, $post->total); ?></td>
<?php
						break;
						case 'title':
?>
				<td><a href="<?php the_permalink(); ?>"><?php the_title() ?></a></td>
<?php
							break;
						case 'categories':
?>
				<td><?php if ($post->post_type == 'post') { the_category(','); } ?></td>
<?php
							break;
						case 'single_views':
?>
				<td class="right"><?php print($post->single_views); ?></td>
<?php
							break;
						case 'searcher_views':
?>
				<td class="right"><?php print($post->searcher_views); ?></td>
<?php
							break;
						case 'category_views':
?>
				<td class="right"><?php print($post->category_views); ?></td>
<?php
							break;
						case 'tag_views':
?>
				<td class="right"><?php print($post->tag_views); ?></td>
<?php
							break;
						case 'archive_views':
?>
				<td class="right"><?php print($post->archive_views); ?></td>
<?php
							break;
						case 'home_views':
?>
				<td class="right"><?php print($post->home_views); ?></td>
<?php
							break;
						case 'feed_views':
?>
				<td class="right"><?php print($post->feed_views); ?></td>
<?php
							break;
						case 'comments':
?>
				<td class="right"><?php print($post->comments); ?></td>
<?php
							break;
						case 'pingbacks':
?>
				<td class="right"><?php print($post->pingbacks); ?></td>
<?php
							break;
						case 'trackbacks':
?>
				<td class="right"><?php print($post->trackbacks); ?></td>
<?php
							break;
					}
				}
?>
		</tr>
<?php
			}
		}
		else {
?>
	  <tr style='background-color: <?php echo $bgcolor; ?>'>
		<td colspan="8"><?php _e('No posts found.') ?></td>
	  </tr>
<?php
		} // end if ($posts)
?>
	</table>
</div>
<?php
	}

	function view_stats($limit = 100) {
		global $wpdb, $post;
		print('
			<div class="wrap ak_wrap">
				<h2>'.__('Most Popular', 'popularity-contest').'</h2>
		');

		$this->show_report_extended('popular', 50);

		print('
				<p id="akpc_options_link"><a href="options-general.php?page=popularity-contest.php">Change Popularity Values</a></p>

				<div class="pop_group">
					<h2>'.__('Date Range', 'popularity-contest').'</h2>
		');

		$this->show_report('last_30');
		$this->show_report('last_60');
		$this->show_report('last_90');
		$this->show_report('last_365');
		$this->show_report('365_plus');

		print('
				</div>
				<div class="clear"></div>
				<div class="pop_group">
					<h2>'.__('Views', 'popularity-contest').'</h2>
		');

		$this->show_report('most_single_views');
		$this->show_report('most_page_views');
		$this->show_report('most_searcher_views');
		$this->show_report('most_category_views');
		$this->show_report('most_tag_views');
		$this->show_report('most_archive_views');
		$this->show_report('most_home_views');
		$this->show_report('most_feed_views');

		print('
				</div>
				<div class="clear"></div>
				<div class="pop_group">
					<h2>'.__('Feedback', 'popularity-contest').'</h2>
		');

		$this->show_report('most_comments');
		$this->show_report('most_pingbacks');
		$this->show_report('most_trackbacks');
		$this->show_report('views_wo_feedback');

		print('
				</div>
				<div class="clear"></div>
				<h2>'.__('Averages', 'popularity-contest').'</h2>
		');

		$this->show_report('category_popularity');
		$this->show_report('tag_popularity');
		$this->show_report('month_popularity');

		print('
				<div class="clear"></div>
				<div class="pop_group" id="akpc_tag_reports">
					<h2>'.__('Tags', 'popularity-contest').'
						<form action="'.site_url('index.php').'" method="post" id="akpc_report_tag_form">
							<label for="akpc_tag_add">'.__('Add report for tag:', 'popularity-contest').'</label>
							<input type="text" name="akpc_tag_add" id="akpc_tag_add" value="" />
							<input type="submit" name="submit_button" value="'.__('Add', 'popularity-contest').'" />
							<input type="hidden" name="ak_action" value="akpc_add_tag" />
							<span class="akpc_saving">'.__('Adding tag...'. 'popularity-contest').'</span>
						</form>
					</h2>
		');

		$this->show_report('pop_by_tag');

		print('
				<div class="akpc_padded none">'.__('No tag reports chosen.', 'popularity-contest').'</div>
				</div>
				<div class="clear"></div>
				<div class="pop_group">
					<h2>'.__('Categories', 'popularity-contest').'</h2>
		');

		$this->show_report('pop_by_category');

		print('
				</div>
				<div class="clear"></div>
			</div>
		');
?>
<script type="text/javascript">
akpc_flow_reports = function() {
	var reports = jQuery('div.akpc_report').css('visibility', 'hidden');
	jQuery('div.akpc-auto-insert').remove();
	var akpc_reports_per_row = Math.floor(jQuery('div.pop_group').width()/250);
	jQuery('div.pop_group').each(function() {
		var i = 1;
		jQuery(this).find('div.akpc_report').each(function() {
			if (i % akpc_reports_per_row == 0) {
				jQuery(this).after('<div class="clear akpc-auto-insert"></div>');
			}
			i++;
		});
	});
	akpc_tag_reports_none();
	reports.css('visibility', 'visible');
}
akpc_tag_report_remove_links = function() {
	jQuery('#akpc_tag_reports a.remove').remove();
	jQuery('#akpc_tag_reports .akpc_report').each(function() {
		jQuery(this).prepend('<a href="<?php echo site_url('index.php?ak_action=akpc_remove_tag&tag='); ?>' + jQuery(this).attr('rel') + '" class="remove"><?php _e('[X]', 'popuarity-contest'); ?></a>');
	});
	jQuery('#akpc_tag_reports a.remove').click(function() {
		report = jQuery(this).parents('#akpc_tag_reports .akpc_report');
		report.html('<div class="akpc_padded"><?php _e('Removing...', 'popularity-contest'); ?></div>');
		jQuery.post(
			'<?php echo site_url('index.php'); ?>',
			{
				'ak_action': 'akpc_remove_tag',
				'tag': report.attr('rel')
			},
			function(response) {
				report.remove();
				akpc_flow_reports();
			},
			'html'
		);
		return false;
	});
}
akpc_tag_reports_none = function() {
	none_msg = jQuery('#akpc_tag_reports .none');
	if (jQuery('#akpc_tag_reports .akpc_report').size()) {
		none_msg.hide();
	}
	else {
		none_msg.show();
	}
}
jQuery(function($) {
	akpc_flow_reports();
	akpc_tag_report_remove_links();
	$('#akpc_tag_add').suggest( 'admin-ajax.php?action=ajax-tag-search&tax=post_tag', { delay: 500, minchars: 2, multiple: true, multipleSep: ", " } );
	$('#akpc_report_tag_form').submit(function() {
		var tag = $('#akpc_tag_add').val();
		if (tag.length > 0) {
			var add_button = $(this).find('input[type="submit"]');
			var saving_msg = $(this).find('span.akpc_saving');
			add_button.hide();
			saving_msg.show();
			$.post(
				'<?php echo site_url('index.php'); ?>',
				{
					'ak_action': 'akpc_add_tag',
					'tag': tag
				},
				function(response) {
					$('#akpc_tag_add').val('');
					$('#akpc_tag_reports').append(response);
					akpc_flow_reports();
					akpc_tag_report_remove_links()
					saving_msg.hide();
					add_button.show();
				},
				'html'
			);
		}
		return false;
	});
});
jQuery(window).bind('resize', akpc_flow_reports);
</script>
<?php
	}

	function get_post_total($post_id) {
		if (!isset($this->current_posts['id_'.$post_id])) {
			$this->get_current_posts(array($post_id));
		}
		return $this->current_posts['id_'.$post_id];
	}

	function get_rank($item, $total = null) {
		if (is_null($total)) {
			$total = $this->top_rank();
		}
		return ceil(($item/$total) * 100).'%';
	}

	function get_post_rank($post_id = null, $total = -1) {
		if (count($this->top_ranked) == 0) {
			$this->get_top_ranked();
		}
		if ($total > -1 && !$post_id) {
			return ceil(($total/$this->top_rank()) * 100).'%';
		}
		if (isset($this->top_ranked['id_'.$post_id])) {
			$rank = $this->top_ranked['id_'.$post_id];
		}
		else {
			$rank = $this->get_post_total($post_id);
		}
		$show_help = apply_filters('akpc_show_help', $this->show_help, $post_id);
		if ($show_help) {
			$suffix = ' <span class="akpc_help">[<a href="http://alexking.org/projects/wordpress/popularity-contest" title="'.__('What does this mean?', 'popularity-contest').'">?</a>]</span>';
		}
		else {
			$suffix = '';
		}
		if (isset($rank) && $rank != false) {
			return __('Popularity:', 'popularity-contest').' '.$this->get_rank($rank).$suffix;
		}
		else {
			return __('Popularity:', 'popularity-contest').' '.__('unranked', 'popularity-contest').$suffix;
		}
	}

	function show_post_rank($post_id = -1, $total = -1) {
		print($this->get_post_rank($post_id, $total));
	}

	function top_rank() {
		if (count($this->top_ranked) == 0) {
			$this->get_top_ranked();
		}
		foreach ($this->top_ranked as $id => $rank) {
			$top = $rank;
			break;
		}
		// handle edge case - div by zero
		if (intval($top) == 0) {
			$top = 1;
		}
		return $top;
	}

	function get_current_posts($post_ids = array()) {
		global $wpdb, $wp_query;
		$posts = $wp_query->get_posts();
		$ids = array();
		foreach ($posts as $post) {
			$ids[] = $post->ID;
		}
		$ids = array_unique(array_merge($ids, $post_ids));
		if (count($ids)) {
			$result = $wpdb->get_results("
				SELECT post_id, total
				FROM $wpdb->ak_popularity
				WHERE post_id IN (".implode(',', $ids).")
			");

			if (count($result)) {
				foreach ($result as $data) {
                                        $ab=get_post_meta($data->post_id,"interest",TRUE);
                                        $score =  round(expect($ab),2);
					$this->current_posts['id_'.$data->post_id] = $score*1000;
				}
			}
		}
		return true;
	}


	function get_top_ranked() {
		global $wpdb;
		$result = $wpdb->get_results("
			SELECT post_id, total
			FROM $wpdb->ak_popularity
			ORDER BY total DESC
			LIMIT 10
		");

                

		if (!count($result)) {
			return false;
		}
		foreach ($result as $data) {

                    $ab=get_post_meta($data->post_id,"interest",TRUE);
                    $score =  round(expect($ab),2);
			$this->top_ranked['id_'.$data->post_id] = $score*1000 ;//$data->total;
		}

		return true;
	}

	function show_top_ranked($limit, $before, $after) {
		if ($posts=$this->get_top_ranked_posts($limit)) {
			foreach ($posts as $post) {
    			print(
    				$before.'<a href="'.get_permalink($post->ID).'">'
    				.$post->post_title.'</a>'.$after
    			);
			}
		}
		else {
			print($before.'(none)'.$after);
		}
	}

	function get_top_ranked_posts($limit) {
		global $wpdb;
		$temp = $wpdb;

		$join = apply_filters('posts_join', '');
		$where = apply_filters('posts_where', '');
		$groupby = apply_filters('posts_groupby', '');
		if (!empty($groupby)) {
			$groupby = ' GROUP BY '.$groupby;
		}
		else {
			$groupby = ' GROUP BY '.$wpdb->posts.'.ID ';
		}

		$posts = $wpdb->get_results("
			SELECT ID, post_title
			FROM $wpdb->posts
			LEFT JOIN $wpdb->ak_popularity pop
			ON $wpdb->posts.ID = pop.post_id
			$join
			WHERE post_status = 'publish'
			AND post_date < NOW()
			$where
			$groupby
			ORDER BY pop.total DESC
			LIMIT ".intval($limit)
		);

		$wpdb = $temp;

		return $posts;
	}

	function show_top_ranked_in_cat($limit, $before, $after, $cat_ID = '') {
		if (empty($cat_ID) && is_category()) {
			global $cat;
			$cat_ID = $cat;
		}
		if (empty($cat_ID)) {
			return;
		}
		global $wpdb;
		$temp = $wpdb;

		$join = apply_filters('posts_join', '');
		$where = apply_filters('posts_where', '');
		$groupby = apply_filters('posts_groupby', '');
		if (!empty($groupby)) {
			$groupby = ' GROUP BY '.$groupby;
		}
		else {
			$groupby = ' GROUP BY p.ID ';
		}

		$posts = $wpdb->get_results("
			SELECT ID, post_title
			FROM $wpdb->posts p
			LEFT JOIN $wpdb->term_relationships tr
			ON p.ID = tr.object_id
			LEFT JOIN $wpdb->term_taxonomy tt
			ON tr.term_taxonomy_id = tt.term_taxonomy_id
			LEFT JOIN $wpdb->ak_popularity pop
			ON p.ID = pop.post_id
			$join
			WHERE tt.term_id = '".intval($cat_ID)."'
			AND tt.taxonomy = 'category'
			AND post_status = 'publish'
			AND post_type = 'post'
			AND post_date < NOW()
			$where
			$groupby
			ORDER BY pop.total DESC
			LIMIT ".intval($limit)
		);
		if ($posts) {
			foreach ($posts as $post) {
    			print(
    				$before.'<a href="'.get_permalink($post->ID).'">'
    				.$post->post_title.'</a>'.$after
    			);
			}
		}
		else {
			print($before.'(none)'.$after);
		}
		$wpdb = $temp;
	}

	function show_top_ranked_in_month($limit, $before, $after, $m = '') {
		if (empty($m) && is_archive()) {
			global $m;
		}
		if (empty($m)) {
			global $post;
			$m = get_the_time('Ym');
		}
		if (empty($m)) {
			return;
		}
		$year = substr($m, 0, 4);
		$month = substr($m, 4, 2);
		global $wpdb;
		$temp = $wpdb;

		$join = apply_filters('posts_join', '');
		$where = apply_filters('posts_where', '');
		$groupby = apply_filters('posts_groupby', '');
		if (!empty($groupby)) {
			$groupby = ' GROUP BY '.$groupby;
		}
		else {
			$groupby = ' GROUP BY '.$wpdb->posts.'.ID ';
		}

		$posts = $wpdb->get_results("
			SELECT ID, post_title
			FROM $wpdb->posts
			LEFT JOIN $wpdb->ak_popularity pop
			ON $wpdb->posts.ID = pop.post_id
			$join
			WHERE YEAR(post_date) = '$year'
			AND MONTH(post_date) = '$month'
			AND post_status = 'publish'
			AND post_date < NOW()
			$where
			$groupby
			ORDER BY pop.total DESC
			LIMIT ".intval($limit)
		);
		if ($posts) {
			foreach ($posts as $post) {
    			print(
    				$before.'<a href="'.get_permalink($post->ID).'">'
    				.$post->post_title.'</a>'.$after
    			);
			}
		}
		else {
			print($before.'(none)'.$after);
		}
		$wpdb = $temp;
	}

	function show_top_ranked_in_last_days($limit, $before, $after, $days = 45) {
		global $wpdb;
		$temp = $wpdb;

		$join = apply_filters('posts_join', '');
		$where = apply_filters('posts_where', '');
		$groupby = apply_filters('posts_groupby', '');
		if (!empty($groupby)) { $groupby = ' GROUP BY '.$groupby; }

		$offset = 0;
		$compare = '>';

		$posts = $wpdb->get_results("
			SELECT ID, post_title
			FROM $wpdb->posts
			LEFT JOIN $wpdb->ak_popularity pop
			ON $wpdb->posts.ID = pop.post_id
			$join
			WHERE DATE_ADD($wpdb->posts.post_date, INTERVAL $days DAY) $compare DATE_ADD(NOW(), INTERVAL $offset DAY)
			AND post_status = 'publish'
			AND post_date < NOW()
			$where
			$groupby
			ORDER BY pop.total DESC
			LIMIT ".intval($limit)
		);
		if ($posts) {
			foreach ($posts as $post) {
    			print(
    				$before.'<a href="'.get_permalink($post->ID).'">'
    				.$post->post_title.'</a>'.$after
    			);
			}
		}
		else {
			print($before.'(none)'.$after);
		}
		$wpdb = $temp;
	}

}

// -- "HOOKABLE" FUNCTIONS

function akpc_init() {
	global $wpdb, $akpc;

	$wpdb->ak_popularity = $wpdb->prefix.'ak_popularity';
	$wpdb->ak_popularity_options = $wpdb->prefix.'ak_popularity_options';

	$akpc = new ak_popularity_contest;
	$akpc->get_settings();
}

function akpc_view($content) {
	global $akpc;
	$akpc->record_view();
	return $content;
}

function akpc_feedback_comment() {
	global $akpc;
	$akpc->record_feedback('comment');
}

function akpc_comment_status($comment_id, $status = 'approved') {
	global $akpc;
	$akpc->edit_feedback($comment_id, 'status', $status);
}

function akpc_comment_delete($comment_id) {
	global $akpc;
	$akpc->edit_feedback($comment_id, 'delete');
}

function akpc_feedback_pingback() {
	global $akpc;
	$akpc->record_feedback('pingback');
}

function akpc_feedback_trackback() {
	global $akpc;
	$akpc->record_feedback('trackback');
}

function akpc_publish($post_id) {
	global $akpc;
	$akpc->create_post_record($post_id);
}

function akpc_post_delete($post_id) {
	global $akpc;
	$akpc->delete_post_record($post_id);
}

function akpc_options_form() {
	global $akpc;
	$akpc->options_form();
}

function akpc_view_stats() {
	global $akpc;
	$akpc->view_stats();
}

function akpc_plugin_action_links($links, $file) {
	$plugin_file = basename(__FILE__);
	if (basename($file) == $plugin_file) {
		$settings_link = '<a href="options-general.php?page='.$plugin_file.'">'.__('Settings', 'popularity-contest').'</a>';
		array_unshift($links, $settings_link);
		$reports_link = '<a href="index.php?page='.$plugin_file.'">'.__('Reports', 'popularity-contest').'</a>';
		array_unshift($links, $reports_link);
	}
	return $links;
}
add_filter('plugin_action_links', 'akpc_plugin_action_links', 10, 2);

function akpc_options() {
	if (function_exists('add_options_page')) {
		add_options_page(
			__('Popularity Contest Options', 'popularity-contest')
			, __('Popularity', 'popularity-contest')
			, 10
			, basename(__FILE__)
			, 'akpc_options_form'
		);
	}
	if (function_exists('add_submenu_page')) {
		add_submenu_page(
			'index.php'
			, __('Most Popular Posts', 'popularity-contest')
			, __('Most Popular Posts', 'popularity-contest')
			, 0
			, basename(__FILE__)
			, 'akpc_view_stats'
		);
	}
}
function akpc_options_css() {
	print('<link rel="stylesheet" type="text/css" href="'.site_url('?ak_action=akpc_css').'" />');
}
function akpc_widget_js() {
	echo '<script type="text/javascript" src="'.site_url('?ak_action=akpc_js').'"></script>';
}

// -- TEMPLATE FUNCTIONS

function akpc_the_popularity($post_id = null) {
	global $akpc;
	if (!$post_id) {
		global $post;
		$post_id = $post->ID;
	}
	$akpc->show_post_rank($post_id);
}

function akpc_most_popular($limit = 10, $before = '<li>', $after = '</li>', $report = false, $echo = true) {
	global $akpc;
	if(!$report) {
		$akpc->show_top_ranked($limit, $before, $after);
	}
	else {
		return $akpc->show_report($report, $limit);
	}
}

/**
 * Show a single report
 * @var string $type - type of report to show
 * @var int $limit - number of results to display
 * @return mixed echo/array
 */
function akpc_show_report($type = 'popular', $limit = 10, $exclude_pages = 'no', $custom = array(), $before_title = '<h3>', $after_title = '</h3>', $hide_title = false) {
	global $akpc;
	return $akpc->show_report($type, $limit, $exclude_pages, $custom, $before_title, $after_title, $hide_title);
}

/**
 * Get raw post data for a report type
 * @var string $type - type of report to show
 * @var int $limit - number of posts to display
 * @var array $custom - any custom report attributes needed
 * @return bool/array - returns false if no posts in report
 */
function akpc_get_popular_posts_array($type, $limit, $custom = array()) {
	global $akpc;
	return $akpc->get_popular_posts($type, $limit, $custom);
}

function akpc_most_popular_in_cat($limit = 10, $before = '<li>', $after = '</li>', $cat_ID = '') {
	global $akpc;
	$akpc->show_top_ranked_in_cat($limit, $before, $after, $cat_ID);
}

function akpc_most_popular_in_month($limit = 10, $before = '<li>', $after = '</li>', $m = '') {
	global $akpc;
	$akpc->show_top_ranked_in_month($limit, $before, $after, $m);
}

function akpc_most_popular_in_last_days($limit = 10, $before = '<li>', $after = '</li>', $days = 45) {
	global $akpc;
	$akpc->show_top_ranked_in_last_days($limit, $before, $after, $days);
}

function akpc_content_pop($str) {
	global $akpc, $post;
	if (is_admin()) {
		return $str;
	}
	else if (is_feed()) {
		$str .= '<img src="'.site_url('?ak_action=api_record_view&id='.$post->ID.'&type=feed').'" alt="" />';
	}
	else {
		if (AKPC_USE_API) {
			$str .= '<script type="text/javascript">AKPC_IDS += "'.$post->ID.',";</script>';
		}
		$show = apply_filters('akpc_display_popularity', $akpc->show_pop, $post);
		if (!get_post_meta($post->ID, 'hide_popularity', true) && $show) {
			$str .= '<p class="akpc_pop">'.$akpc->get_post_rank($post->ID).'</p>';
		}
	}
	return $str;
}

function akpc_excerpt_compat_pre($output) {
	remove_filter('the_content', 'akpc_content_pop');
	return $output;
}
add_filter('get_the_excerpt', 'akpc_excerpt_compat_pre', 1);

function akpc_excerpt_compat_post($output) {
	add_filter('the_content', 'akpc_content_pop');
	return $output;
}
add_filter('get_the_excerpt', 'akpc_excerpt_compat_post', 999);

// -- WIDGET

/**
 * do widget init functionality
 */
function akpc_widget_init() {
	if(!function_exists('register_sidebar_widget') || !function_exists('register_widget_control')) { return; }

	// get existing widget options
	$options = maybe_unserialize(get_option('akpc_widget_options'));

	// if no existing widgets, fake one
	if(!is_array($options)) { $options[-1] = array('title'=>'','type'=>'','limit'=>''); }

	// base set of options for widget type
	$base_options = array('classname' => 'akpc-widget', 'description' => __('Show popular posts as ranked by Popularity Contest', 'popularity-contest'));
	$widget_name = __('Popularity Contest', 'popularity-contest');

	// register widgets & controls for each existing widget
	foreach($options as $number => $option) {
		$widget_id = 'akpc-widget-'.($number === -1 ? 1 : $number); // not needed, but avoids duplicate dashes for new widgets
		wp_register_sidebar_widget($widget_id, $widget_name,'akpc_widget', $base_options, array('number' => $number));
		wp_register_widget_control($widget_id, $widget_name,'akpc_widget_control', array('id_base' => 'akpc-widget'), array('number' => $number));
	}
}

/**
 * Widget display
 */
function akpc_widget($args, $widget_args = 1) {
	// find out which widget we're working on
	if(is_numeric($widget_args)) {
		$widget_args = array('number' => $widget_args);
	}
	$widget_args = wp_parse_args( $widget_args, array( 'number' => -1 ) );
	extract($widget_args, EXTR_SKIP);

	// get passed args
	extract($args);

	// get saved options
	$options = maybe_unserialize(get_option('akpc_widget_options'));
	extract($options[$number]);
	$type = (!isset($type) || empty($type)) ? 'popular' : $type;
	$days = (!isset($days) || empty($days)) ? '' : intval($days);
	$limit = (!isset($limit) || empty($limit)) ? 10 : intval($limit);
	$title_string = (!isset($title) || empty($title)) ? '' : $before_title.htmlspecialchars(stripslashes($title)).$after_title;
	$exclude_pages = (!isset($exclude_pages) || empty($exclude_pages)) ? 'no' : $exclude_pages;
	$custom = array();

	// Check to see if we have the custom type of "last_n" and pass the day amount in the custom array
	if ($type == 'last_n') {
		$custom['days'] = $days;
	}

	// output
	echo $before_widget.$title_string;
	akpc_show_report($type, $limit, $exclude_pages, $custom, '<h4>', '<h4>', true);
	echo $after_widget;
}

/**
 * Controls for creating and saving multiple PC widgets
 */
function akpc_widget_control($widget_args = 1) {
	global $wp_registered_widgets;
	static $updated = false; // set this after updating so update only happens once

	// get individual widget ID
	if(is_numeric($widget_args)) {
		$widget_args = array('number' => $widget_args);
	}
	$widget_args = wp_parse_args( $widget_args, array( 'number' => -1 ) );
	extract( $widget_args, EXTR_SKIP );

	// get existing widget options
	$options = maybe_unserialize(get_option('akpc_widget_options'));

	/* UPDATE OPTIONS ON PRESENCE OF POST DATA */
	if(isset($_POST['akpc']) && isset($_POST['sidebar'])) {
		// get current sidebar data
		$sidebar = strval($_POST['sidebar']);
		$sidebar_widgets = wp_get_sidebars_widgets();
		$this_sidebar = isset($sidebar_widgets[$sidebar]) ? $sidebar_widgets[$sidebar] : array();

		// check to see if this sidebar item needs to be deleted
		// code pulled directly from the Plain Text widget native to wordpress
		foreach ($this_sidebar as $_widget_id) {
			if ('akpc_widget' == $wp_registered_widgets[$_widget_id]['callback'] && isset($wp_registered_widgets[$_widget_id]['params'][0]['number'])) {
				$widget_number = $wp_registered_widgets[$_widget_id]['params'][0]['number'];
				// if we had a previously registered widget ID but nothing in the post args, the widget was removed, so kill it
				if(!in_array("akpc-widget-$widget_number", $_POST['widget-id'])) {
					unset($options[$widget_number]);
				}
			}
		}

		// save this widget's options
		foreach((array) $_POST['akpc'] as $widget_number => $widget_options) {
			$options[$widget_number]['title'] = $widget_options['title'];
			$options[$widget_number]['type'] = $widget_options['type'];
			$options[$widget_number]['days'] = intval($widget_options['days']);
			$options[$widget_number]['limit'] = intval($widget_options['limit']);
			$options[$widget_number]['exclude_pages'] = $widget_options['exclude_pages'];
		}
		update_option('akpc_widget_options',serialize($options));
		$updated = true;
	}

	// new widget prep
	if($number == -1) {
		$options[$number] = array('title'=>'','type'=>'','days'=>'','limit'=>'');
		$number = '%i%'; // required for new widgets so WP auto-generates a new ID
	}

	/* START CONTROLS OUTPUT */
	// Widget Title
	echo '<p>
			<label for="akpc['.$number.'][title]">'.__('Widget Title', 'popularity-contest').'</label>
			<input type="text" id="akpc['.$number.'][title]" name="akpc['.$number.'][title]" value="'.htmlspecialchars(stripslashes($options[$number]['title'])).'" />
		</p>'.PHP_EOL;

	// report type select list
	$report_types = array('popular' => __('Most Popular', 'popularity-contest'),
						  'pop_by_category' => __('By Category', 'popularity-contest'),
						  'category_popularity' => __('Average by Category', 'popularity-contest'),
						  'last_30' => __('Last 30 Days', 'popularity-contest'),
						  'last_60' => __('Last 60 Days', 'popularity-contest'),
						  'last_90' => __('Last 90 Days', 'popularity-contest'),
						  'last_n' => __('Last (n) Days', 'popularity-contest'),
						  '365_plus' => __('Older than 1 Year', 'popularity-contest'),
						  'year' => __('Average by Year', 'popularity-contest'),
						  'views_wo_feedback' => __('Views w/o Feedback', 'popularity-contest'),
						  'most_feedback' => __('Most Feedback', 'popularity-contest'),
						  'most_comments' => __('Most Commented', 'popularity-contest'),
						  'most_feed_views' => __('Feed Views', 'popularity-contest'),
						  'most_home_views' => __('Home Page Views', 'popularity-contest'),
						  'most_archive_views' => __('Archive Views', 'popularity-contest'),
						  'most_single_views' => __('Permalink Views', 'popularity-contest'),
						  'most_pingbacks' => __('Pingbacks', 'popularity-contest'),
						  'most_trackbacks' => __('Trackbacks', 'popularity-contest')
						  );
	echo '<p>
			<label for="akpc['.$number.'][type]">'.__('Report Type', 'popularity-contest').'</label>
			<select id="akpc['.$number.'][type]" name="akpc['.$number.'][type]" class="akpc_pop_widget_type">'.PHP_EOL;
	foreach($report_types as $key => $value) {
		echo '<option value="'.$key.'"'.($key == $options[$number]['type'] ? ' selected="selected"' : '').'>'.$value.'</option>'.PHP_EOL;
	}
	echo '</select>
		</p>'.PHP_EOL;
	// Number of days to get data from
	$hide_days = '';
	if ($options[$number]['type'] != 'last_n' || (!is_int($options[$number]['days']) && $options[$number]['days'] != 0)) {
		$hide_days = ' style="display:none;"';
	}
	echo '<p class="akpc_pop_widget_days"'.$hide_days.'>
			<label for="akpc['.$number.'][days]">'.__('Number of days', 'popularity-contest').': </label>
			<input type="text" id="akpc['.$number.'][days]" name="akpc['.$number.'][days]" size="3" value="'.$options[$number]['days'].'" />
		</p>'.PHP_EOL;

	// number of posts to display
	echo '<p>
			<label for="akpc['.$number.'][limit]">'.__('Number of posts to display', 'popularity-contest').': </label>
			<input type="text" id="akpc['.$number.'][limit]" name="akpc['.$number.'][limit]" size="3" value="'.$options[$number]['limit'].'" />
		</p>'.PHP_EOL;
	// exclude pages
	echo '<p>
			<label for="akpc['.$number.'][limit]">'.__('Exclude pages', 'popularity-contest').': </label>
			<select id="akpc['.$number.'][exclude_pages]" name="akpc['.$number.'][exclude_pages]">
				<option value="yes"'.('yes' == $options[$number]['exclude_pages'] ? ' selected="selected"' : '').'>Yes</option>
				<option value="no"'.('no' == $options[$number]['exclude_pages'] ? ' selected="selected"' : '').'>No</option>
			</select>
		</p>'.PHP_EOL;
	// submit hidden field, really necessary? may be needed for legacy compatability
	echo '<input type="hidden" id="akpc['.$number.'][submit]" name="akpc['.$number.'][submit]" value="1" />';
}

function akpc_show_error($type, $info = null) {
	switch ($type) {
		case 'tag_report_not_found':
			echo '<div class="akpc_report"><div class="akpc_padded">'.sprintf(__('Sorry, could not find the requested tag: %s', 'popularity-contest'), htmlspecialchars($info)).'</div></div>';
			break;
		case 'tag_report_already_added':
			echo '<div class="akpc_report"><div class="akpc_padded">'.sprintf(__('Looks like you already have a report for tag: %s', 'popularity-contest'), htmlspecialchars($info)).'</div></div>';
			break;
	}
}

// -- API FUNCTIONS

function akpc_api_head_javascript() {
	echo '
<script type="text/javascript">var AKPC_IDS = "";</script>
	';
}

function akpc_api_footer_javascript() {
	if (function_exists('akpc_is_searcher') && akpc_is_searcher()) {
		$type = 'searcher';
	}
	else if (is_archive() && !is_category()) {
		$type = 'archive';
	}
	else if (is_category()) {
		$type = 'category';
	}
	else if (is_single()) {
		$type = 'single';
	}
	else if (is_tag()) {
		$type = 'tag';
	}
	else if (is_page()) {
		$type = 'page';
	}
	else {
		$type = 'home';
	}
	echo '
<script type="text/javascript">
jQuery(function() {

	jQuery.post("index.php",{ak_action:"api_record_view", ids: AKPC_IDS, type:"'.$type.'"}, false, "json");
});
</script>
	';
}

function akpc_is_searcher() {
	global $akpc;

	$temp = parse_url($_SERVER['HTTP_REFERER']);
	$referring_domain = $temp['host'];
	$searchers = ereg_replace("\n|\r|\r\n|\n\r", " ", $akpc->searcher_names);
	$searchers = explode(" ", $searchers);
	foreach ($searchers as $searcher) {
		if ($referring_domain == $searcher) { return true; }
	}
	return false;
}

function akpc_api_record_view($id = null) {
	global $wpdb;
	$akpc = new ak_popularity_contest;
	$akpc->get_settings();

	$ids = array();
	if ($id) {
		$ids[] = $id;
	}
	else {
		foreach (explode(',', $_POST['ids']) as $id) {
			if ($id = intval($id)) {
				$ids[] = $id;
			}
		}
	}
	array_unique($ids);

	if (!empty($_GET['type'])) {
		$type = $_GET['type'];
		$response = 'img';
	}
	else {
		$type = $_POST['type'];
		$response = 'json';
	}
	if (count($ids) && $akpc->record_view(true, $ids, $type)) {
		$json = '{"result":true,"ids":"'.implode(',',$ids).'","type":"'.sanitize_title($type).'"}';
	}
	else {
		$json = '{"result":false,"ids":"'.implode(',',$ids).'","type":"'.sanitize_title($type).'"}';
	}
	switch ($response) {
		case 'img':
			header('Content-type: image/jpeg');
			break;
		case 'json':
			header('Content-type: application/json');
			echo $json;
			break;
	}
	exit();
}

// -- HANDLE ACTIONS

function akpc_request_handler() {
	if (!empty($_POST['ak_action'])) {
		switch($_POST['ak_action']) {
			case 'update_popularity_values':
				if (current_user_can('manage_options')) {
					$akpc = new ak_popularity_contest;
					$akpc->get_settings();
					$akpc->update_settings();
				}
				break;
			case 'api_record_view':
				akpc_api_record_view();
				break;
			case 'akpc_add_tag':
				if (!empty($_POST['tag']) && current_user_can('manage_options')) {
					$akpc = new ak_popularity_contest;
					if (strpos($_POST['tag'], ',')) {
						$added_tags = explode(',', $_POST['tag']);
					}
					else {
						$added_tags = array($_POST['tag']);
					}
					$tag_reports = get_option('akpc_tag_reports');
					if ($tag_reports == '') {
						add_option('akpc_tag_reports');
					}
					$tags = maybe_unserialize($tag_reports);
					if (!is_array($tags)) {
						$tags = array();
					}
					foreach ($added_tags as $tag) {
						$tag = sanitize_title_with_dashes(trim($tag));
						if (!empty($tag)) {
							if (in_array($tag, $tags)) {
								akpc_show_error('tag_report_already_added', $tag);
							}
							else if ($term = get_term_by('slug', $tag, 'post_tag')) {
								$tags[] = $tag;
								$akpc->show_report('tag', 10, 'yes', array('term_id' => $term->term_id, 'term_name' => $term->name));
							}
							else {
								akpc_show_error('tag_report_not_found', $tag);
							}
						}
					}
					$tags = array_unique($tags);
					update_option('akpc_tag_reports', $tags);
				}
				die();
				break;
			case 'akpc_remove_tag':
				if (!empty($_POST['tag']) && current_user_can('manage_options')) {
					$tag = sanitize_title(trim($_POST['tag']));
					if (!empty($tag)) {
						$tags = maybe_unserialize(get_option('akpc_tag_reports'));
						if (is_array($tags) && count($tags)) {
							$new_tags = array();
							foreach ($tags as $existing_tag) {
								if ($existing_tag != $tag) {
									$new_tags[] = $existing_tag;
								}
							}
							$tags = array_unique($new_tags);
							update_option('akpc_tag_reports', $tags);
						}
					}
				}
				die();
				break;
		}
	}
	if (!empty($_GET['ak_action'])) {
		switch($_GET['ak_action']) {
			case 'api_record_view':
				if (isset($_GET['id']) && $id = intval($_GET['id'])) {
					akpc_api_record_view($id);
				}
				break;
			case 'recount_feedback':
				if (current_user_can('manage_options')) {
					$akpc = new ak_popularity_contest;
					$akpc->get_settings();
					$akpc->recount_feedback();
				}
				break;
			case 'akpc_css':
				header("Content-type: text/css");
?>
.ak_wrap {
	padding-bottom: 40px;
}
#akpc_most_popular {
	height: 250px;
	overflow: auto;
	margin-bottom: 10px;
}
#akpc_most_popular .alternate {
	background: #efefef;
}
#akpc_most_popular td.right, #akpc_options_link {
	text-align: right;
}
#akpc_most_popular td {
	padding: 3px;
}
#akpc_most_popular td a {
	border: 0;
}
.akpc_report {
	float: left;
	margin: 5px 30px 20px 0;
	width: 220px;
}
.akpc_report h3 {
	border-bottom: 1px solid #999;
	color #333;
	margin: 0 0 4px 0;
	padding: 0 0 2px 0;
}
.akpc_report ol {
	margin: 0;
	padding: 0 0 0 30px;
}
.akpc_report ol li span {
	float: right;
}
.akpc_report ol li a {
	border: 0;
	display: block;
	margin: 0 30px 0 0;
}
.clear {
	clear: both;
	float: none;
}
#akpc_template_tags dl {
	margin-left: 10px;
}
#akpc_template_tags dl dt {
	font-weight: bold;
	margin: 0 0 5px 0;
}
#akpc_template_tags dl dd {
	margin: 0 0 15px 0;
	padding: 0 0 0 15px;
}
#akpc_options th {
	font-weight: normal;
	text-align: left;
}
#akpc_options input.number {
	width: 40px;
}
#akpc_report_tag_form {
	display: inline;
	padding-left: 20px;
}
#akpc_report_tag_form label, .akpc_saving {
	font: normal normal 12px "Lucida Grande",Verdana,Arial,"Bitstream Vera Sans",sans-serif;
}
.akpc_saving {
	color: #999;
	display: none;
	padding: 5px;
}
#akpc_tag_reports h3 {
	padding-right: 20px;
}
#akpc_tag_reports a.remove {
	float: right;
}
#akpc_tag_reports .akpc_padded {
	color: #999;
	padding: 20px;
	text-align: center;
}
#akpc_tag_reports .none {
	background: #eee;
	text-align: left;
}
<?php
				die();
				break;
			case 'akpc_js':
				header('Content-type: text/javascript');
				?>
				var cf_widget_count = 0;
				jQuery(function($) {
					akpc_widget_js();
					setInterval('akpc_widget_check()', 500);
				});
				akpc_widget_js = function() {
					jQuery('select.akpc_pop_widget_type').unbind().change(function() {
						if (jQuery(this).val() == 'last_n') {
							jQuery(this).parents('div.widget-content, div.widget-control').find('p.akpc_pop_widget_days:hidden').slideDown();
						}
						else {
							jQuery(this).parents('div.widget-content, div.widget-control').find('p.akpc_pop_widget_days:visible').slideUp();
						}
					});
				}
				akpc_widget_check = function() {
					var current_count = jQuery('#widgets-right .widget-inside:visible, .widget-control-list .widget-list-control-item').size();
					if (current_count != cf_widget_count) {
						akpc_widget_js();
						cf_widget_count = current_count;
					}
				}
<?php
				die();
				break;
		}
	}
}

// -- GET HOOKED

if (is_admin() && $_GET['page'] == 'popularity-contest.php') {
	wp_enqueue_script('suggest');
}

add_filter('the_content', 'akpc_content_pop');

add_action('init', 'akpc_init', 1);
add_action('init', 'akpc_request_handler', 2);
add_action('admin_menu', 'akpc_options');
add_action('admin_head', 'akpc_options_css');


// Use the global pagenow so we only load the Widget JS on the widget page
global $pagenow;
if ($pagenow == 'widgets.php') {
	add_action('admin_head', 'akpc_widget_js');
}

if (AKPC_USE_API == 0) {
	// work cache unfriendly
	add_action('the_content', 'akpc_view');
}
else {
	// do view updates via API
	add_action('wp_head','akpc_api_head_javascript');
	add_action('wp_footer','akpc_api_footer_javascript');
	wp_enqueue_script('jquery');
}
add_action('comment_post', 'akpc_feedback_comment');
add_action('pingback_post', 'akpc_feedback_pingback');
add_action('trackback_post', 'akpc_feedback_trackback');

add_action('publish_post', 'akpc_publish');
add_action('delete_post', 'akpc_post_delete');

add_action('publish_page', 'akpc_publish');
add_action('delete_page', 'akpc_post_delete');

add_action('wp_set_comment_status', 'akpc_comment_status', 10, 2);
add_action('delete_comment', 'akpc_comment_delete');

add_action('plugins_loaded','akpc_widget_init');

endif; // LOADED CHECK

?>