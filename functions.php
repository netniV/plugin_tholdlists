<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2017 The Cacti Group                                 |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/
global $tholdlists_import_type_fields;
global $tholdlists_import_type_names;

$tholdlists_import_type_fields = array(0 => 'notify_alert', 1 => 'notify_warning');
$tholdlists_import_type_names = array(0 => 'Alert', 1 => 'Warning');

function get_tholdlists_list_field($list_type) {
	global $tholdlists_import_type_fields;
	if (!isset($list_type) || !is_numeric($list_type)) {
		$list_type = 0;
	}
	return array_key_exists($list_type, $tholdlists_import_type_fields) ? ($tholdlists_import_type_fields[$list_type]) : ('unknown_field_'.$list_type);
}

function get_tholdlists_list_name($list_type) {
	global $tholdlists_import_type_names;
	if (!isset($list_type) || !is_numeric($list_type)) {
		$list_type = 0;
	}
	return array_key_exists($list_type, $tholdlists_import_type_names) ? ($tholdlists_import_type_names[$list_type]) : ('Unknown Type '.$list_type);
}

function tholdlists_calc_next_start($import, $start_time = 0) {
	if ($start_time == 0) $start_time = time();

	$poller_interval = read_config_option('poller_interval');

	if ($import['import_timing'] == 'periodic') {
		$now        = date('Y-m-d H:i:00', time());
		$next_run   = strtotime($now) + $import['import_skip'] * $poller_interval;
		$next_start = date('Y-m-d H:i:s', $next_run);
	}else{
		switch($import['import_timing']) {
		case 'hourly':
			$next_start = date('Y-m-d H:' . $import['import_hourly'] . ':00', $start_time);
			$now_time   = strtotime(date('Y-m-d H:i:00', $start_time));
			$next_run   = strtotime($next_start);
			if ($next_run <= $now_time) {
				$next_run += 3600;
			}

			$next_start = date('Y-m-d H:i:00', $next_run);

			break;
		case 'daily':
			$next_start = date('Y-m-d ' . $import['import_daily'] . ':00', $start_time);
			$now_time   = strtotime(date('Y-m-d H:i:00', $start_time));
			$next_run   = strtotime($next_start);
			if ($next_run <= $now_time) {
				$next_run += 86400;
			}

			$next_start = date('Y-m-d H:i:00', $next_run);

			break;
		}
	}

	return $next_start;
}

/* import_list_start - a function that determines, for each export definition
   if it's time to run or not.  this function is currently single threaded
   and some thought should be given to making multi-threaded.
   @arg $id    - the id of the export to check, '0' for all export definitions.
   @arg $force - force the export to run no regardless of it's timing settings. */
function tholdlists_import_list_start($id = 0, $force = false) {
	global $debug, $start;

	/* take time to log performance data */
	$start           = microtime(true);
	$start_time      = time();
	$poller_interval = read_config_option('poller_interval');
	$runnow          = false;
	$sql_where       = '';
	$started_imports = 0;

	if ($force) {
		plugin_tholdlists_log('DEBUG: This is a forced run');
	}

	/* force run */
	if ($id > 0) {
		$sql_where = ' AND id=' . $id;
	}

	$imports = db_fetch_assoc('SELECT *
		FROM mbv_thold_lists
		WHERE enabled="on"' . $sql_where);

	if (sizeof($imports)) {
		foreach($imports as $import) {
			plugin_tholdlists_log("DEBUG: Checking import '" . $import['name'] . "' to determine if it's time to run.");

			/* insert poller stats into the settings table */
			db_execute_prepared('UPDATE mbv_thold_lists
				SET last_checked = NOW()
				WHERE id = ?',
				array($import['id']));

			$runnow = false;
			if (!$force) {
				if (strtotime($import['next_start']) < $start_time) {
					$runnow = true;
					$next_start = tholdlists_calc_next_start($import);

					db_execute_prepared('UPDATE mbv_thold_lists
						SET next_start = ? WHERE id = ?',
						array($next_start, $import['id']));
				}
			}else{
				$runnow = true;
			}

			if ($runnow) {
				$started_imports++;
				plugin_tholdlists_log('DEBUG: Running Import for id ' . $import['id']);
				tholdlists_run_import($import);
			}
		}
	}

	$end = microtime(true);

	$import_stats = sprintf('Time:%01.2f Imports:%s Imported:%s', $end - $start, sizeof($imports), $started_imports);

	cacti_log('STATS: ' . $import_stats, true, 'THOLDLISTS');
}

/* run_export - a function the pre-processes the export structure and
   then executes the required functions to export graphs, html and
   config, to sanitize directories, and transfer data to the remote
   host(s).
   @arg $import   - the export item structure. */
function tholdlists_run_import(&$import) {
	global $config, $import_file;

	$imported = 0;

	if (!empty($import['import_pid'])) {
		plugin_tholdlists_log('WARNING: Previous run of the following Notification List Import ended in an unclean state Import:' . $import['name']);

		if (posix_kill($import['import_pid'], 0) !== false) {
			plugin_tholdlists_log('WARNING: Can not start the following Notification List Import:' . $import['name'] . ' is still running');
			return;
		}
	}

	db_execute_prepared('UPDATE mbv_thold_lists
		SET import_pid = ?, status = 1, last_started=NOW()
		WHERE id = ?',
		array(getmypid(), $import['id']));

	plugin_tholdlists_import_list($import);

	db_execute_prepared('UPDATE mbv_thold_lists SET import_pid = 0 WHERE id = ?', array($import['id']));

	tholdlists_import_stats($import, $imported);
}

/* tholdlists_import_stats - a function to export stats to the Cacti system for information
   and possible graphing. It uses a global variable to get the start time of the
   export process.
   @arg $import   - the export item structure
   @arg $imported - the number of graphs exported. */
function tholdlists_import_stats(&$import, $imported) {
	global $start;
	/* take time to log performance data */
	$end = microtime(true);

	$import_stats = sprintf(
		'TholdListID:%s (%s) ImportDate:%s ImportDuration:%01.2f TotalImported:%s',
		$import['id'], $import['name'], date('Y-m-d_G:i:s'), $end - $start, $imported);

	cacti_log('STATS: ' . $import_stats, true, 'THOLDLISTS');

	/* insert poller stats into the settings table */
	db_execute_prepared('UPDATE mbv_thold_lists
		SET last_runtime = ?, total_lists = ?, last_ended=NOW(), status=0
		WHERE id = ?',
		array($end - $start, $imported, $import['id']));

	db_execute_prepared(sprintf("REPLACE INTO settings (name,value) values ('stats_import_tholdlists_%s', ?)", $import['id']), array($import_stats));
}

function plugin_tholdlists_messagetype($message) {
	$types = array('ERROR:','FATAL:','STATS:','WARNING:','NOTICE:','DEBUG:');
	$typepos = array();
	foreach ($types as $type) {
		$pos = strpos($message,$type);
		if ($pos !== false) {
			$typepos[$pos] = $type;
		}
	}

	ksort($typepos);
	foreach ($typepos as $pos=>$type) {
		return $type;
	}

	return $message;
}

/*
//Log messages to cacti log or syslog
//This function is the same as thold plugin with a litle changes
//to respect cacti log level settings
*/
function plugin_tholdlists_log($message, $log_level = POLLER_VERBOSITY_NONE) {
	global $config, $debug;

	$environ = 'THOLDLISTS';

	if ($log_level == POLLER_VERBOSITY_NONE) {
		$log_level = POLLER_VERBOSITY_HIGH;

		$message_type = plugin_tholdlists_messagetype($message);
		if (substr_count($message_type,'ERROR:') || substr_count($message_type, 'FATAL:') || substr_count($message_type,'STATS:')) {
			$log_level = POLLER_VERBOSITY_LOW;
		} else if (substr_count($message_type,'WARNING:') || substr_count($message_type,'NOTICE:')) {
			$log_level = POLLER_VERBOSITY_MEDIUM;
		} else if (substr_count($message_type,'DEBUG:')) {
			$log_level = POLLER_VERBOSITY_DEBUG;
		}
	}

	if ($debug) {
		$log_level = POLLER_VERBOSITY_NONE;
	}
	cacti_log($message,!$config['is_web'],$environ, $log_level);
}

/* plugin_tholdlists_fatal - a simple export logging function that indicates a
   fatal condition for developers and users.
   @arg $import    - the export item structure
   @arg $stMessage - the debug message. */
function plugin_tholdlists_fatal(&$import, $stMessage) {
	plugin_tholdlists_log('FATAL ERROR: ' . $stMessage, POLLER_VERBOSITY_NONE);

	/* insert poller stats into the settings table */
	db_execute_prepared('UPDATE mbv_thold_lists
		SET last_error = ?, last_ended=NOW(), last_errored=NOW(), status=2
		WHERE id = ?',
		array($stMessage, $import['id']));

	exit;
}

/* tholdlists_check_cacti_paths - this function is looking for bad export paths that
   can potentially get the user in trouble.  We avoid paths that can
   get erased by accident.
   @arg $import       - the export item structure
   @arg $import_file  - the directory holding the export contents. */
function tholdlists_check_cacti_paths(&$import, $import_file) {
	global $config;

	$root_path = $config['base_path'];

	/* check for bad directories within the cacti path */
	if (strcasecmp($root_path, $import_file) < 0) {
		$cacti_system_paths = array(
			'include',
			'lib',
			'install',
			'rra',
			'log',
			'scripts',
			'plugins',
			'images',
			'resource');

		foreach($cacti_system_paths as $cacti_system_path) {
			if (substr_count(strtolower($import_file), strtolower($cacti_system_path)) > 0) {
				plugin_tholdlists_fatal($import, "Import path '" . $import_file . "' is potentially within a Cacti system path '" . $cacti_system_path . "'.  Can not continue.");
			}
		}
	}

	/* can not be the web root */
	if ((strcasecmp($root_path, $import_file) == 0) &&
		(read_config_option('import_type') == 'local')) {
		plugin_tholdlists_fatal($import, "Import path '" . $import_file . "' is the Cacti web root.  Can not continue.");
	}

	/* can not be a parent of the Cacti web root */
	if (strncasecmp($root_path, $import_file, strlen($import_file))== 0) {
		plugin_tholdlists_fatal($import, "Import path '" . $import_file . "' is a parent folder from the Cacti web root.  Can not continue.");
	}

}

function tholdlists_check_system_paths(&$import, $import_file) {
	/* don't allow to export to system paths */
	$system_paths = array(
		'/boot',
		'/lib',
		'/usr',
		'/usr/bin',
		'/bin',
		'/sbin',
		'/usr/sbin',
		'/usr/lib',
		'/var/lib',
		'/var/log',
		'/root',
		'/etc',
		'windows',
		'winnt',
		'program files');

	foreach($system_paths as $system_path) {
		if (substr($system_path, 0, 1) == '/') {
			if ($system_path == substr($import_file, 0, strlen($system_path))) {
				plugin_tholdlists_fatal($import, "Import path '" . $import_file . "' is within a system path '" . $system_path . "'.  Can not continue.");
			}
		}elseif (substr_count(strtolower($import_file), strtolower($system_path)) > 0) {
			plugin_tholdlists_fatal($import, "Import path '" . $import_file . "' is within a system path '" . $system_path . "'.  Can not continue.");
		}
	}
}

/* plugin_tholdlists_import_list - this function exports all the graphs and some html for
   mgtg view data.  these are all the graphs that are in scope for the export
   be it a tree export, or a site export.
   @arg $import       - the export item structure
   @arg $import_file  - the directory holding the export contents. */
function plugin_tholdlists_import_list(&$import) {
	global $config;

	$import_file = $import['import_file'];

	/* check for bad directories */
	tholdlists_check_cacti_paths($import, $import_file);
	tholdlists_check_system_paths($import, $import_file);

	if (strlen($import_file) < 3) {
		plugin_tholdlists_fatal($import, "Import path is not long enough ! Import can not continue. ");
	}
	/* if the path is not a directory, don't continue */
	clearstatcache();
	if (!file_exists($import_file)) {
		plugin_tholdlists_fatal($import, "Unable to find file '" . $import_file . "'!  Import can not continue.");
	}

	clearstatcache();
	plugin_tholdlists_log('DEBUG: Running tholdlist import');

	$import_id     = $import['id'];
	$import_prefix = $import['import_prefix'];
	$import_time   = time();

	$handle = @fopen($import_file, "r");
	$imported = 0;
	if ($handle) {
		plugin_tholdlists_log("DEBUG: Successfully opened '$import_file'");
		while (($line = fgets($handle)) !== false) {
			$imported++;
			$log_row = 'Row ' . sprintf('%4s', $imported) . ' -';

			$line = str_replace("\n","",$line);
			if (!isset($header)) {
				$header = $line;
				if (strcasecmp('Name|Email|Graphs', $header) !== 0) {
					plugin_tholdlists_log('ERROR: ' . $log_row .' Import aborted as Header ('. $header .') does not match expecations');
					break;
				}

				if ($import['import_clear'] == 'on') {
					$notify_lists = db_fetch_assoc('SELECT * FROM plugin_notification_lists
						WHERE name LIKE (\'%$import_prefix%)\'');

					if (sizeof($notify_lists)) {
						foreach ($notify_lists as $notify_list) {
							plugin_tholdlists_log('DEBUG: ' . $log_row . ' Would remove \'' . $notify_list['name'] . '\' (' . $notify_list['id'] . ')');
						}
					}

					db_execute('TRUNCATE TABLE mbv_thold_lists_contacts');
				}


			} else {
				$data = explode("|",$line);
				if (sizeof($data) != 3) {
					plugin_tholdlists_log('ERROR: ' . $log_row . ' has wrong number of elements: '. sizeof($data));
				} else {
					$notify_name = $data[0];
					$notify_emails = $data[1];
					$notify_graphs = $data[2];

					$import_full = trim($import_prefix) . ' ' . $notify_name;

					$notify_list = db_fetch_row_prepared('SELECT * FROM plugin_notification_lists
						WHERE name = ?',
						array($import_prefix . ' ' . $notify_name));

					$list_id = 0;
					if (sizeof($notify_list)) {
						db_execute_prepared('UPDATE plugin_notification_lists
							SET emails = ?, description = ?
							WHERE name = ?',
							array($notify_emails, 'Import of ' . $notify_name, $import_full));

						$list_id = $notify_list['id'];
					} else {
						db_execute_prepared('INSERT INTO plugin_notification_lists (name, emails, description)
							VALUES (?, ?, ?)',
							array($import_full, $notify_emails, 'Import of ' . $notify_name));

						$list_id = db_fetch_insert_id();
					}

					$imported++;

					$log = 'NOTICE: ';
					$log .= $log_row . ' ';
					$log .= (sizeof($notify_list) ? 'Updated' : 'Created');
					$log .= ' notification list \'';
					$log .= $import_full;
					$log .= '\' (';
					$log .= $list_id;
					$log .= ')';
					plugin_tholdlists_log($log);

					db_execute_prepared('INSERT INTO mbv_thold_lists_contacts (tholdlist_id, list_id, name, graph_ids, date_imported, date_inserted)
						VALUES (?, ?, ?, ?, ?, ?)',
						array($import_id, $list_id, $notify_name, $notify_graphs, $import_time, time()));

					$graph_ids = array();
					$bad_graph_ids = array();
					foreach (explode(',', $notify_graphs) as $graph_id) {
						if (is_numeric($graph_id)) {
							$graph_ids[] = $graph_id;
						} else {
							$bad_graph_ids[] = $graph_id;
						}
					}

					if (sizeof($bad_graph_ids)) {
						plugin_tholdlists_log('WARNING: ' . $log_row . ' Invalid Graph ID\'s found, skipped');
					} elseif (sizeof($graph_ids)) {
						$tholds = db_fetch_assoc('SELECT id, name, local_graph_id, notify_alert FROM thold_data
							WHERE local_graph_id IN (' . implode(',',$graph_ids) .')
							ORDER BY local_graph_id, name');

						plugin_tholdlists_log('DEBUG: ' . $log_row . ' Found ' . sizeof($tholds) .' thresholds to update');
						if (sizeof($tholds)) {
							$local_graph_id = -1;
							$thold_ids = array();
							foreach ($tholds as $thold) {
								if ($local_graph_id != $thold['local_graph_id']) {
									$local_graph_id = $thold['local_graph_id'];
									plugin_tholdlists_log('DEBUG: ' . $log_row . ' Processing Graph ' . $local_graph_id);
								}

								if ($thold['notify_alert'] != $list_id) {
									plugin_tholdlists_log('DEBUG: ' . $log_row . ' -> ' . $thold['name'] . ' (ID: ' . $thold['id'] .')');
									$thold_ids[] = $thold['id'];
								}
							}

							if (sizeof($thold_ids)) {
								$list_field = get_tholdlists_list_field($import['import_type']);
								$list_name = get_tholdlists_list_name($import['import_type']);

								if ($import['import_thold'] == 'on') {
									plugin_tholdlists_log('WARNING: ' . $log_row . ' -> ' .
										'Updating ' . $list_name . ' thresholds (IDs: ' .
										implode(',', $thold_ids) . ')');

									db_execute_prepared('UPDATE thold_data
										SET ' . $list_field . ' = ?
										WHERE id in (?)',
										array($list_id, implode(',',$thold_ids)));
								} else {
									plugin_tholdlists_log('WARNING: ' . $log_row . ' -> ' .
										'Skipped ' . $list_name . ' thresholds (IDs: ' .
										implode(',', $thold_ids) . ')');
								}
							}
						}
					}
				}
			}
    		}
		fclose($handle);
	} else {
		// error opening the file.
	}
	return $imported;
}
