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

chdir('../../');
include('include/auth.php');
include_once('plugins/tholdlists/functions.php');

define('THOLDLISTS_ACTION_DUPLICATE','1');
define('THOLDLISTS_ACTION_DELETE','2');
define('THOLDLISTS_ACTION_ENABLE','3');
define('THOLDLISTS_ACTION_DISABLE','4');
define('THOLDLISTS_ACTION_IMPORT','5');

$import_actions = array(
	THOLDLISTS_ACTION_DUPLICATE => __('Duplicate', 'tholdlists'),
	THOLDLISTS_ACTION_DELETE => __('Delete', 'tholdlists'),
	THOLDLISTS_ACTION_ENABLE => __('Enable', 'tholdlists'),
	THOLDLISTS_ACTION_DISABLE => __('Disable', 'tholdlists'),
	THOLDLISTS_ACTION_IMPORT => __('Import Now', 'tholdlists')
);

$import_timing = array(
	__('Periodic', 'tholdlists'),
	__('Daily', 'tholdlists'),
	__('Hourly', 'tholdlists')
);

set_default_action();

switch (get_request_var('action')) {
	case 'save':
		tholdlists_import_form_save();

		break;
	case 'actions':
		tholdlists_import_form_actions();

		break;
	case 'edit':
		top_header();

		tholdlists_import_edit();

		bottom_footer();

		break;
	default:
		top_header();

		tholdlists();

		bottom_footer();

		break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function tholdlists_import_form_save() {
	if (isset_request_var('save_component_import')) {
		$save['id']                      = get_filter_request_var('id');
		$save['name']                    = form_input_validate(get_nfilter_request_var('name'), 'name', '', false, 3);
		$save['enabled']                 = isset_request_var('enabled') ? 'on':'';
		$save['import_clear']            = isset_request_var('import_clear') ? 'on':'';
		$save['import_thold']            = isset_request_var('import_thold') ? 'on':'';
		$save['import_file']             = form_input_validate(get_nfilter_request_var('import_file'), 'import_file', '', false, 3);
		$save['import_prefix']           = form_input_validate(get_nfilter_request_var('import_prefix'), 'import_prefix', '', false, 3);
		$save['import_timing']           = form_input_validate(get_nfilter_request_var('import_timing'), 'import_timing', '^periodic|hourly|daily$', false, 3);
		$save['import_skip']             = form_input_validate(get_nfilter_request_var('import_skip'), 'import_skip', '^[0-9]+$', false, 3);
		$save['import_hourly']           = form_input_validate(get_nfilter_request_var('import_hourly'), 'import_hourly', '^[0-9]+$', false, 3);
		$save['import_daily']            = form_input_validate(get_nfilter_request_var('import_daily'), 'import_daily', '^[0-9]+:[0-9]+$', false, 3);

		/* determine the start time */
		$next_start = tholdlists_calc_next_start($save);
		$save['next_start'] = $next_start;

		$import_id = sql_save($save, 'mbv_thold_lists');

		if ($import_id) {
			raise_message(1);
		} else {
			raise_message(2);
		}

		header('Location: tholdlists.php?action=edit&header=false&id=' . (empty($import_id) ? get_request_var('id') : $import_id));
	}
}

function tholdlists_import_duplicate($import_id, $import_title) {
	global $fields_tholdlists_import_edit;

	if (isset($import_id)) {
		if (!is_array($import_id)) {
			$import_id = array($import_id);
		}

		foreach ($import_id as $id) {
			$import = db_fetch_row_prepared('SELECT * FROM mbv_thold_lists WHERE id = ?', array($id));

			/* substitute the title variable */
			$import['name'] = str_replace('<import_title>', $import['name'], $import_title);

			/* create new entry: device_template */
			$save['id']   = 0;

			reset($fields_tholdlists_import_edit);
			while (list($field, $array) = each($fields_tholdlists_import_edit)) {
				if (!preg_match('/^hidden/', $array['method'])) {
					$save[$field] = $import[$field];
				}
			}

			$import_id = sql_save($save, 'mbv_thold_lists');
		}
	}
}

/* ------------------------
    The 'actions' function
   ------------------------ */

function tholdlists_import_form_actions() {
	global $import_actions, $tholdlists_import_type_names, $tholdlists_import_type_fields;

	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			if (get_nfilter_request_var('drp_action') === THOLDLISTS_ACTION_DUPLICATE) { /* duplicate */
				if (sizeof($selected_items)) {
					foreach($selected_items as $import_id) {
						/* ================= input validation ================= */
						input_validate_input_number($import_id);
						/* ==================================================== */
					}

					tholdlists_import_duplicate($import_id,'Duplicate of ');
				}
			}

			if (get_nfilter_request_var('drp_action') === THOLDLISTS_ACTION_DELETE) { /* delete */
				/* do a referential integrity check */
				if (sizeof($selected_items)) {
					foreach($selected_items as $import_id) {
						/* ================= input validation ================= */
						input_validate_input_number($import_id);
						/* ==================================================== */
					}

					tholdlists_import_delete($selected_items);
				}
			} elseif (get_nfilter_request_var('drp_action') === THOLDLISTS_ACTION_ENABLE) { /* enable */
				if (sizeof($selected_items)) {
					foreach($selected_items as $import_id) {
						/* ================= input validation ================= */
						input_validate_input_number($import_id);
						/* ==================================================== */
					}

					tholdlists_import_enable($selected_items);
				}
			} elseif (get_nfilter_request_var('drp_action') === THOLDLISTS_ACTION_DISABLE) { /* disable */
				if (sizeof($selected_items)) {
					foreach($selected_items as $import_id) {
						/* ================= input validation ================= */
						input_validate_input_number($import_id);
						/* ==================================================== */
					}

					tholdlists_import_disable($selected_items);
				}
			} elseif (get_nfilter_request_var('drp_action') === THOLDLISTS_ACTION_IMPORT) { /* run now */
				if (sizeof($selected_items)) {
					foreach($selected_items as $import_id) {
						/* ================= input validation ================= */
						input_validate_input_number($import_id);
						/* ==================================================== */
					}

					tholdlists_import_runnow($selected_items);
				}
			}
		}

		header('Location: tholdlists.php?header=false');

		exit;
	}

	/* setup some variables */
	$import_list = '';

	/* loop through each of the graphs selected on the previous page and get more info about them */
	while (list($var,$val) = each($_POST)) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$import_list .= '<li>' . db_fetch_cell_prepared('SELECT name FROM mbv_thold_lists WHERE id = ?', array($matches[1])) . '</li>';
			$import_array[] = $matches[1];
		}
	}

	top_header();

	form_start('tholdlists.php', 'import_actions');

	html_start_box($import_actions{get_nfilter_request_var('drp_action')}, '60%', '', '3', 'center', '');

	if (isset($import_array)) {
		if (get_nfilter_request_var('drp_action') === THOLDLISTS_ACTION_DUPLICATE) { /* duplicate */
			print "	<tr>
					<td class='topBoxAlt'>
						<p>" . __n('Click \'Continue\' to duplicate the following Notification List Import.', 'Click \'Continue\' to duplicate following Notification List Imports.', sizeof($import_array), 'tholdlists') . "</p>
						<div class='itemlist'><ul>$import_list</ul></div>
					</td>
				</tr>\n";

			$save_html = "<input type='button' value='" . __esc('Cancel', 'tholdlists') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='Continue' title='Delete Notification List Import(s)'>";
		} elseif (get_nfilter_request_var('drp_action') === THOLDLISTS_ACTION_DELETE) { /* delete */
			print "	<tr>
					<td class='topBoxAlt'>
						<p>" . __n('Click \'Continue\' to delete the following Notification List Import.', 'Click \'Continue\' to delete following Notification List Imports.', sizeof($import_array), 'tholdlists') . "</p>
						<div class='itemlist'><ul>$import_list</ul></div>
					</td>
				</tr>\n";

			$save_html = "<input type='button' value='" . __esc('Cancel', 'tholdlists') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='Continue' title='Delete Notification List Import(s)'>";
		} elseif (get_nfilter_request_var('drp_action') === THOLDLISTS_ACTION_DISABLE) { /* disable */
			print "	<tr>
					<td class='topBoxAlt'>
						<p>" . __n('Click \'Continue\' to disable the following Notification List Import.', 'Click \'Continue\' to disable following Notification List Imports.', sizeof($import_array), 'tholdlists') . "</p>
						<div class='itemlist'><ul>$import_list</ul></div>
					</td>
				</tr>\n";

			$save_html = "<input type='button' value='" . __esc('Cancel', 'tholdlists') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __esc('Continue', 'tholdlists') . "' title='" . __esc('Disable Notification List Import(s)', 'tholdlists') . "'>";
		} elseif (get_nfilter_request_var('drp_action') === THOLD_ACTIONS_LIST_ENABLE) { /* enable */
			print "	<tr>
					<td class='topBoxAlt'>
						<p>" . __n('Click \'Continue\' to enable the following Notification List Import.', 'Click \'Continue\' to enable following Notification List Imports.', sizeof($import_array), 'tholdlists') . "</p>
						<div class='itemlist'><ul>$import_list</ul></div>
					</td>
				</tr>\n";

			$save_html = "<input type='button' value='" . __esc('Cancel', 'tholdlists') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __esc('Continue', 'tholdlists') . "' title='" . __esc('Enable Notification List Import(s)', 'tholdlists') . "'>";
		} elseif (get_nfilter_request_var('drp_action') === THOLD_ACTION_LISTS_IMPORT) { /* import now */
			print "	<tr>
					<td class='topBoxAlt'>
						<p>" . __n('Click \'Continue\' to run the following Notification List Import now.', 'Click \'Continue\' to run following Notification List Imports now.', sizeof($import_array)) . "</p>
						<div class='itemlist'><ul>$import_list</ul></div>
					</td>
				</tr>\n";

			$save_html = "<input type='button' value='" . __esc('Cancel', 'tholdlists') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __esc('Continue', 'tholdlists') . "' title='" . __esc('Run Notification List Import(s) Now', 'tholdlists') . "'>";
		}
	} else {
		print "<tr><td class='odd'><span class='textError'>" . __('You must select at least one Notification List Import.', 'tholdlists') . "</span></td></tr>\n";
		$save_html = "<input type='button' value='" . __esc('Return', 'tholdlists') . "' onClick='cactiReturnTo()'>";
	}

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($import_array) ? serialize($import_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . get_nfilter_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>\n";

	html_end_box();

	form_end();

	bottom_footer();
}

function tholdlists_import_delete($import_id) {
	if (isset($import_id)) {
		if (!is_array($import_id)) {
			$import_id = array($import_id);
		}
		db_execute_prepared('DELETE FROM mbv_thold_lists WHERE id in (?)', array(implode(',',$import_id)));
	}
}

function tholdlists_import_enable($import_id) {
	if (isset($import_id)) {
		if (!is_array($import_id)) {
			$import_id = array($import_id);
		}
		db_execute_prepared('UPDATE mbv_thold_lists SET enabled="on" WHERE id IN (?)', array(import(',',$import_id)));
	}
}

function tholdlists_import_disable($import_id) {
	if (isset($import_id)) {
		if (!is_array($import_id)) {
			$import_id = array($import_id);
		}
		db_execute_prepared('UPDATE mbv_thold_lists SET enabled="" WHERE id IN (?)', array(import(',',$import_id)));
	}
}

function tholdlists_import_runnow($import_id) {
	global $config;

	if (isset($import_id)) {
		include_once('./lib/poller.php');

		if (!is_array($import_id)) {
			$import_id = array($import_id);
		}

		$statuses = db_fetch_assoc_prepared('SELECT status, enabled FROM mbv_thold_lists WHERE id IN (?)', array(implode(',',$import_id)));
		if (sizeof($statuses)) {
			foreach ($statuses as $status) {
				if (($status['status'] == 0 || $status['status'] == 2) && $status['enabled'] == 'on') {
					$command_string = read_config_option('path_php_binary');
					$extra_args = '-q "' . $config['base_path'] . '/plugins/tholdlists/poller_import.php" --id=' . $import_id . ' --force';
					exec_background($command_string, $extra_args);
				}
				sleep(2);
			}
		}
	}
}

function tholdlists_import_edit() {
	global $fields_tholdlists_import_edit, $tholdlists_import_type_fields, $tholdlists_import_type_names;

	/* ================= input validation ================= */
	get_filter_request_var('id');
	/* ==================================================== */

	if (!isempty_request_var('id')) {
		$import = db_fetch_row_prepared('SELECT * FROM mbv_thold_lists WHERE id = ?', array(get_request_var('id')));
		$header_label = __('Notification List Import [edit: %s]', $import['name'], 'tholdlists');
	} else {
		$header_label = __('Notification List Import [new]', 'tholdlists');
	}

	form_start('tholdlists.php', 'import_edit');

	html_start_box($header_label, '100%', '', '3', 'center', '');

	echo "<!-- Edit Fields: " . var_export($fields_tholdlists_import_edit, true) . " -->";
	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => inject_form_variables($fields_tholdlists_import_edit, (isset($import) ? $import : array()))
		)
	);

	html_end_box();

	form_hidden_box('id', (isset($import['id']) ? $import['id'] : '0'), '');
	form_hidden_box('save_component_import', '1', '');

	form_save_button('tholdlists.php', 'return');

	?>
	<script type='text/javascript'>

	$(function() {
		setTimingVisibility();

		$('#import_timing').change(function() {
			setTimingVisibility();
		});
	});

	function setTimingVisibility() {
		if ($('#import_timing').val() == 'periodic') {
			$('#row_import_skip').show();
			$('#row_import_hourly').hide();
			$('#row_import_daily').hide();
		}else if ($('#import_timing').val() == 'hourly') {
			$('#row_import_skip').hide();
			$('#row_import_hourly').show();
			$('#row_import_daily').hide();
		}else if ($('#import_timing').val() == 'daily') {
			$('#row_import_skip').hide();
			$('#row_import_hourly').hide();
			$('#row_import_daily').show();
		} else {
			$('#row_import_skip').hide();
			$('#row_import_hourly').hide();
			$('#row_import_daily').hide();
		}
	}
	</script>
	<?php
}

function tholdlists_import_filter() {
	global $item_rows;

	html_start_box(__('Notification List Imports', 'tholdlists'), '100%', '', '3', 'center', 'tholdlists.php?action=edit');
	?>
	<tr class='even'>
		<td>
			<form id='form_import' action='tholdlists.php'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search', 'tholdlists');?>
					</td>
					<td>
						<input type='text' id='filter' size='25' value='<?php print get_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Imports', 'tholdlists');?>
					</td>
					<td>
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>':'>') . __('Default', 'tholdlists');?></option>
							<?php
							if (sizeof($item_rows)) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" . $key . "'"; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . $value . "</option>\n";
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input type='button' Value='<?php print __x('filter: use', 'Go', 'tholdlists');?>' id='refresh'>
							<input type='button' Value='<?php print __x('filter: reset', 'Clear', 'tholdlists');?>' id='clear'>
						</span>
					</td>
				</tr>
			</table>
			</form>
			<script type='text/javascript'>

			function applyFilter() {
				strURL  = 'tholdlists.php?header=false';
				strURL += '&filter='+$('#filter').val();
				strURL += '&rows='+$('#rows').val();
				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				strURL = 'tholdlists.php?clear=1&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#refresh').click(function() {
					applyFilter();
				});

				$('#clear').click(function() {
					clearFilter();
				});

				$('#form_import').submit(function(event) {
					event.preventDefault();
					applyFilter();
				});
			});

			</script>
		</td>
	</tr>
	<?php

	html_end_box();
}

function tholdlists_get_import_records(&$total_rows, &$rows) {
	/* form the 'where' clause for our main sql query */
	if (get_request_var('filter') != '') {
		$sql_where = "WHERE (name LIKE '%" . get_request_var('filter') . "%')";
	} else {
		$sql_where = '';
	}

	$total_rows = db_fetch_cell("SELECT COUNT(*) FROM mbv_thold_lists $sql_where");

	$sql_order = get_order_string();
	$sql_limit = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;

	return db_fetch_assoc("SELECT *
		FROM mbv_thold_lists
		$sql_where
		$sql_order
		$sql_limit");
}

function tholdlists($refresh = true) {
	global $import_actions;

    /* ================= input validation and session storage ================= */
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
			),
		'filter' => array(
			'filter' => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'name',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
			)
	);

	validate_store_request_vars($filters, 'sess_tholdlists');
	/* ================= input validation ================= */

	tholdlists_import_filter();

	$total_rows = 0;
	$imports = array();

	if (get_request_var('rows') == '-1') {
		$rows = read_config_option('num_rows_table');
	} else {
		$rows = get_request_var('rows');
	}

	$imports = tholdlists_get_import_records($total_rows, $rows);

	$nav = html_nav_bar('tholdlists.php?filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 5, __('Import Definitions', 'tholdlists'), 'page', 'main');

	form_start('tholdlists.php', 'chk');

    print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = array(
		'name' => array('display' => __('Import Name', 'tholdlists'), 'align' => 'left', 'sort' => 'ASC', 'tip' => __('The name of this Notification List Import.', 'tholdlists')),
		'id' => array('display' => __('ID', 'tholdlists'), 'align' => 'right', 'sort' => 'ASC', 'tip' => __('The internal ID of the Notification List Import.', 'tholdlists')),
		'import_timing' => array('display' => __('Schedule', 'tholdlists'), 'align' => 'right', 'sort' => 'DESC', 'tip' => __('The frequency that Lists will be imported.', 'tholdlists')),
		'next_start' => array('display' => __('Next Start', 'tholdlists'), 'align' => 'right', 'sort' => 'ASC', 'tip' => __('The next time the Notification List Imports should run.', 'tholdlists')),
		'enabled' => array('display' => __('Enabled', 'tholdlists'), 'align' => 'right', 'tip' => __('If enabled, this Notification List Import will run as required.', 'tholdlists')),
		'cleared' => array('display' => __('Cleared', 'tholdlists'), 'align' => 'right', 'tip' => __('If enabled, this Notification List Import will clear as required.', 'tholdlists')),
		'thresholds' => array('display' => __('Thresholds', 'tholdlists'), 'align' => 'right', 'tip' => __('If enabled, this Notification List Import will update Thresholds as required.', 'tholdlists')),
		'status' => array('display' => __('Status', 'tholdlists'), 'align' => 'right', 'tip' => __('The current Notification List Import Status.', 'tholdlists')),

		'last_runtime' => array('display' => __('Last Runtime', 'tholdlists'), 'align' => 'right', 'sort' => 'ASC', 'tip' => __('The last runtime for the Notification List Import.', 'tholdlists')),
		'last_started' => array('display' => __('Last Started', 'tholdlists'), 'align' => 'right', 'sort' => 'ASC', 'tip' => __('The last time that this Notification List Import was started.', 'tholdlists')),
		'last_errored' => array('display' => __('Last Errored', 'tholdlists'), 'align' => 'right', 'sort' => 'ASC', 'tip' => __('The last time that this Notification List Import experienced an error.', 'tholdlists'))
	);

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

	$i = 0;
	if (sizeof($imports)) {
		foreach ($imports as $import) {
			if ($import['import_pid'] > 0 && $import['status'] > 0) {
				if (function_exists('posix_getpgid')) {
					$running = posix_getpgid($import['import_pid']);
				} elseif (function_exists('posix_kill')) {
					$running = posix_kill($import['import_pid'], 0);
				}

				if (!$running) {
					db_execute_prepared('UPDATE mbv_thold_lists
						SET status=0, import_pid=0, last_error="Killed Outside Cacti", last_errored=NOW() 
						WHERE id = ?',
						array($import['id']));
				}
			}

			form_alternate_row('line' . $import['id'], true);
			form_selectable_cell(filter_value($import['name'], get_request_var('filter'), 'tholdlists.php?action=edit&id=' . $import['id']), $import['id']);
			form_selectable_cell($import['id'], $import['id'], '', 'text-align:right');

			form_selectable_cell(__(ucfirst($import['import_timing']), 'tholdlists'), $import['id'], '', 'text-align:right');

			form_selectable_cell($import['enabled'] == '' ? __('N/A', 'tholdlists'):substr($import['next_start'], 5, 11), $import['id'], '', 'text-align:right');

			form_selectable_cell($import['enabled'] == '' ? __('No', 'tholdlists'):__('Yes', 'tholdlists'), $import['id'], '', 'text-align:right');
			form_selectable_cell($import['import_clear'] == '' ? __('No', 'tholdlists'):__('Yes', 'tholdlists'), $import['id'], '', 'text-align:right');
			form_selectable_cell($import['import_thold'] == '' ? __('No', 'tholdlists'):__('Yes', 'tholdlists'), $import['id'], '', 'text-align:right');

			switch($import['status']) {
			case '0':
				form_selectable_cell("<span class='idle'>" .  __('Idle', 'tholdlists') . "</span>", $import['id'], '', 'text-align:right');
				break;
			case '1':
				form_selectable_cell("<span class='running'>" .  __('Running', 'tholdlists') . "</span>", $import['id'], '', 'text-align:right');
				break;
			case '2':
				form_selectable_cell("<span class='errored'>" .  __('Error', 'tholdlists') . "</span>", $import['id'], '', 'text-align:right');
				break;
			}

			if ($import['last_started'] != '0000-00-00 00:00:00') {
				form_selectable_cell(round($import['last_runtime'],2) . ' ' . __('Sec', 'tholdlists'), $import['id'], '', 'text-align:right');
				form_selectable_cell(substr($import['last_started'], 5, 11), $import['id'], '', 'text-align:right');

				if ($import['last_errored'] != '0000-00-00 00:00:00') {
					form_selectable_cell(substr($import['last_errored'], 5, 11), $import['id'], '', 'text-align:right', $import['last_error']);
				} else {
					form_selectable_cell(__('Never', 'tholdlists'), $import['id'], '', 'text-align:right');
				}
			} else {
				form_selectable_cell(__('N/A', 'tholdlists'), $import['id'], '', 'text-align:right');
				form_selectable_cell(__('N/A', 'tholdlists'), $import['id'], '', 'text-align:right');
				form_selectable_cell(__('Never', 'tholdlists'), $import['id'], '', 'text-align:right');
			}

			form_checkbox_cell($import['name'], $import['id']);
			form_end_row();
		}
	} else {
		print "<tr class='tableRow'><td colspan='4'><em>" . __('No Notification List Imports', 'tholdlists') . "</em></td></tr>\n";
	}

	html_end_box(false);

	if (sizeof($imports)) {
		print $nav;
	}

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($import_actions);

	form_end();
}

