<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2018 Mark Brugnoli-Vinten                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | https://www.github.com/netniv/plugin_tholdlists                         |
 +-------------------------------------------------------------------------+
*/

function plugin_tholdlists_install() {
	# graph setup all arrays needed for automation
	api_plugin_register_hook('tholdlists', 'config_arrays',        'tholdlists_config_arrays',        'setup.php');
	api_plugin_register_hook('tholdlists', 'draw_navigation_text', 'tholdlists_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('tholdlists', 'poller_bottom',        'tholdlists_poller_bottom',        'setup.php');

	api_plugin_register_realm('tholdlists', 'tholdlists.php', __('THold Notification Lists Settings', 'tholdlists'), 1);

	tholdlists_setup_table();
}

function plugin_tholdlists_uninstall() {
	db_execute('DROP TABLE mbv_thold_lists');

	return true;
}

function plugin_tholdlists_check_config() {
	return true;
}

function plugin_tholdlists_version() {
	global $config;
	$info = parse_ini_file($config['base_path'] . '/plugins/tholdlists/INFO', true);
	return $info['info'];
}

function tholdlists_poller_bottom() {
	global $config;

	if ($config['poller_id'] == 1) {
		$imports = db_fetch_assoc('SELECT * FROM mbv_thold_lists WHERE enabled="on"');
		if (sizeof($imports)) {
			$command_string = read_config_option('path_php_binary');
			$extra_args = '-q "' . $config['base_path'] . '/plugins/tholdlists/poller_import.php"';
			exec_background($command_string, $extra_args);
		}
	}
}

function tholdlists_check_upgrade() {
	global $config, $database_default;

	include_once($config['library_path'] . '/database.php');
	include_once($config['library_path'] . '/functions.php');

	// Let's only run this check if we are on a page that actually needs the data
	$files = array('plugins.php', 'tholdlists.php');
	if (!in_array(get_current_page(), $files)) {
		return;
	}

	$info    = plugin_tholdlists_version();
	$current = $info['version'];
	$old     = db_fetch_cell("SELECT version FROM plugin_config WHERE directory='tholdlists'");

	if ($current != $old) {
		if (api_plugin_is_enabled('tholdlists')) {
			# may sound ridiculous, but enables new hooks
			api_plugin_enable_hooks('tholdlists');
		}

		db_execute("UPDATE plugin_config
			SET version='$current'
			WHERE directory='tholdlists'");

		db_execute("UPDATE plugin_config SET
			version='" . $info['version']  . "',
			name='"    . $info['longname'] . "',
			author='"  . $info['author']   . "',
			webpage='" . $info['homepage'] . "'
			WHERE directory='" . $info['name'] . "' ");
	}
}

function tholdlists_check_dependencies() {
	return true;
}

function tholdlists_setup_table() {
	global $config, $database_default;
	include_once($config['library_path'] . '/database.php');

	tholdlists_create_table();

	return true;
}

function tholdlists_create_table() {
	if (!db_table_exists('mbv_thold_lists')) {
		db_execute("CREATE TABLE `mbv_thold_lists` (
			`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			`name` varchar(64) DEFAULT '',
			`enabled` char(3) DEFAULT 'on',
			`import_prefix` varchar(255) DEFAULT '',
			`import_file` varchar(255) DEFAULT '',
			`import_timing` varchar(20) DEFAULT 'disabled',
			`import_skip` int(10) unsigned DEFAULT '0',
			`import_hourly` varchar(20) DEFAULT '',
			`import_daily` varchar(20) DEFAULT '',
			`import_clear` char(3) DEFAULT '',
			`status` int(10) unsigned DEFAULT '0',
			`import_pid` int(10) unsigned DEFAULT NULL,
			`next_start` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
			`last_checked` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
			`last_started` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
			`last_ended` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
			`last_errored` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
			`last_runtime` double NOT NULL DEFAULT '0',
			`last_error` varchar(255) DEFAULT NULL,
			`total_items` double DEFAULT '0',
			PRIMARY KEY (`id`))
			ENGINE=InnoDB
			COMMENT='Stores THold Notification List Import Settings for Cacti'");
	}
	return true;
}

function tholdlists_config_arrays() {
	global $menu, $fields_tholdlists_import_edit, $messages, $config;

	/* perform database upgrade, if required */
	tholdlists_check_upgrade();

	$dir = dir($config['base_path'] . '/include/themes/');
	while (false !== ($entry = $dir->read())) {
		if ($entry != '.' && $entry != '..') {
			if (is_dir($config['base_path'] . '/include/themes/' . $entry)) {
				$themes[$entry] = ucwords($entry);
			}
		}
	}
	asort($themes);
	$dir->close();

	// Replace OS check and fixed temp dir with sys_get_temp_dir()
	$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;

	if (isset($_SESSION['tholdlists_message']) && $_SESSION['tholdlists_message'] != '') {
		$messages['tholdlists_message'] = array('message' => $_SESSION['tholdlists_message'], 'type' => 'info');
	}

	$menu[__('Management', 'tholdlists')]['plugins/tholdlists/tholdlists.php'] = __('Notification List Imports', 'tholdlists');

	$fields_tholdlists_import_edit = array(
		'tholdlists_hdr_general' => array(
			'friendly_name' => __('General', 'tholdlists'),
			'collapsible' => 'true',
			'method' => 'spacer',
		),
		'name' => array(
			'friendly_name' => __('Notification List Import Name', 'tholdlists'),
			'description' => __('The name of this notification list import.', 'tholdlists'),
			'method' => 'textbox',
			'value' => '|arg1:name|',
			'default' => 'New Notification List Import',
			'max_length' => '64',
			'size' => '40'
		),
		'enabled' => array(
			'friendly_name' => __('Enabled', 'tholdlists'),
			'description' => __('Check this Checkbox if you wish this notification list import to be enabled.', 'tholdlists'),
			'value' => '|arg1:enabled|',
			'default' => 'on',
			'method' => 'checkbox',
		),
		'clear' => array(
			'friendly_name' => __('Clear', 'tholdlists'),
			'description' => __('Check this Checkbox if you wish to clear matching notification lists during import.', 'tholdlists'),
			'value' => '|arg1:import_clear|',
			'method' => 'checkbox',
		),
		'import_hdr_paths' => array(
			'friendly_name' => __('Import Location Information', 'tholdlists'),
			'collapsible' => 'true',
			'method' => 'spacer',
		),
		'import_file' => array(
			'friendly_name' => __('Import File', 'tholdlists'),
			'description' => __('This is the file that will contain the exported data that needs importing.', 'tholdlists'),
			'method' => 'textbox',
			'value' => '|arg1:import_file|',
			'max_length' => '255'
		),
		'import_prefix' => array(
			'friendly_name' => __('Import Prefix', 'tholdlists'),
			'description' => __('This is the string that will prefix the name of the notification list name in the import file', 'tholdlists'),
			'method' => 'textbox',
			'value' => '|arg1:import_prefix|',
			'max_length' => '255'
		),
		'import_hdr_timing' => array(
			'friendly_name' => __('Import Timing', 'tholdlists'),
			'method' => 'spacer',
		),
		'import_timing' => array(
			'friendly_name' => __('Timing Method', 'tholdlists'),
			'description' => __('Choose when to Import Graphs.', 'tholdlists'),
			'method' => 'drop_array',
			'value' => '|arg1:import_timing|',
			'default' => 'import_hourly',
			'array' => array(
				'periodic' => __('Periodic', 'tholdlists'),
				'hourly' => __('Hourly', 'tholdlists'),
				'daily' => __('Daily', 'tholdlists')
			),
		),
		'import_skip' => array(
			'friendly_name' => __('Periodic Import Cycle', 'tholdlists'),
			'description' => __('How often do you wish Cacti to Import Graphs.  This is the unit of Polling Cycles you wish to activate the import.', 'tholdlists'),
			'method' => 'drop_array',
			'value' => '|arg1:import_skip|',
			'array' => array(
				1  => __('Every Polling Cycle', 'tholdlists'),
				2  => __('Every %d Polling Cycles', 2, 'tholdlists'),
				3  => __('Every %d Polling Cycles', 3, 'tholdlists'),
				4  => __('Every %d Polling Cycles', 4, 'tholdlists'),
				5  => __('Every %d Polling Cycles', 5, 'tholdlists'),
				6  => __('Every %d Polling Cycles', 6, 'tholdlists'),
				7  => __('Every %d Polling Cycles', 7, 'tholdlists'),
				8  => __('Every %d Polling Cycles', 8, 'tholdlists'),
				9  => __('Every %d Polling Cycles', 9, 'tholdlists'),
				10 => __('Every %d Polling Cycles', 10, 'tholdlists'),
				11 => __('Every %d Polling Cycles', 11, 'tholdlists'),
				12 => __('Every %d Polling Cycles', 12, 'tholdlists')
			),
		),
		'import_hourly' => array(
			'friendly_name' => __('Hourly at specified minutes', 'tholdlists'),
			'description' => __('If you want Cacti to import on an hourly basis, put the minutes of the hour when to do that. Cacti assumes that you run the data gathering script every 5 minutes, so it will round your value to the one closest to its runtime. For instance, 43 would equal 40 minutes past the hour.', 'tholdlists'),
			'method' => 'textbox',
			'placeholder' => 'MM',
			'value' => '|arg1:import_hourly|',
			'default' => '00',
			'max_length' => '10',
			'size' => '5'
		),
		'import_daily' => array(
			'friendly_name' => __('Daily at specified time', 'tholdlists'),
			'description' => __('If you want Cacti to import on an daily basis, put here the time to do that. Cacti assumes that you run the data gathering script every poller internal, so it will round your value to the one closest to its runtime. For instance, 21:23 would equal 20 minutes after 9 PM.', 'tholdlists'),
			'method' => 'textbox',
			'placeholder' => 'HH:MM',
			'value' => '|arg1:import_daily|',
			'default' => '00:00',
			'max_length' => '10',
			'size' => '5'
		),
	);
}

function tholdlists_draw_navigation_text($nav) {
	$nav['tholdlists.php:'] = array(
		'title' => __('THold Notification List Import', 'tholdlists'),
		'mapping' => 'index.php:',
		'url' => 'tholdlists.php',
		'level' => '1');

	$nav['tholdlists.php:edit'] = array(
		'title' => __('(Edit)', 'tholdlists'),
		'mapping' => 'index.php:,tholdlists.php:',
		'url' => '',
		'level' => '2');

	$nav['tholdlists.php:actions'] = array(
		'title' => __('Actions', 'tholdlists'),
		'mapping' => 'index.php:,tholdlists.php:',
		'url' => '',
		'level' => '2');

	return $nav;
}

