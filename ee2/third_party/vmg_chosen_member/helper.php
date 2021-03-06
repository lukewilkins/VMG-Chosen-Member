<?php if ( ! defined('BASEPATH')) die('No direct script access allowed');

// include config file
require_once PATH_THIRD.'vmg_chosen_member/config.php';

/**
 * VMG Chosen Member Helper Class
 *
 * @package     VMG Chosen Member
 * @author      Luke Wilkins <luke@vectormediagroup.com>
 * @copyright   Copyright (c) 2011-2014 Vector Media Group, Inc.
 */
class ChosenHelper
{
    protected $disallowed_fields = array('password', 'unique_id', 'crypt_key', 'salt');

    public $default_search_fields = array(
        'username' => 'Username', 'screen_name' => 'Screen Name', 'email' => 'Email', 'url' => 'URL',
        'location' => 'Location', 'occupation' => 'Occupation', 'interests' => 'Interests',
        'aol_im' => 'AOL IM', 'yahoo_im' => 'Yahoo! IM', 'msn_im' => 'MSN IM', 'icq' => 'ICQ',
        'bio' => 'Bio', 'signature' => 'Signature',
    );

    public function __construct()
    {
        // Prep cache
        if ( ! isset(ee()->session->cache['vmg_chosen_member'])) {
            ee()->session->cache['vmg_chosen_member'] = array();
        }

        $this->cache =& ee()->session->cache['vmg_chosen_member'];
    }

    /**
     * Gather associated member data info
     */
    public function memberAssociations($entry_id = null, $field_id = null, $col_id = null, $row_id = null, $var_id = null, $settings = null, $select_fields = null, $group_by = 'vcm.member_id')
    {
        $cache_key = serialize(func_get_args());

        if ( ! isset($this->cache['memberAssociations'][$cache_key])) {

            // Return specific fields from query
            if ( ! is_null($select_fields)) {
                ee()->db->select($select_fields);
            } else {
                ee()->db->select('m.*');
            }

            ee()->db->from('vmg_chosen_member AS vcm')
                ->join('members AS m', 'm.member_id = vcm.member_id', 'inner');

            // Add join to member_data if that is within the select statement
            if (strpos($select_fields, 'md.') !== false) {
                ee()->db->join('member_data AS md', 'md.member_id = vcm.member_id', 'inner');
            }

            if ( ! is_null($entry_id)) {
                ee()->db->where('vcm.entry_id', $entry_id);
            }

            // Make general restrictions for this particular field
            ee()->db->where('vcm.field_id', $field_id);
            ee()->db->where('vcm.col_id', (is_null($col_id) ? '0' : $col_id));
            if ( ! is_null($row_id)) ee()->db->where('vcm.row_id', $row_id);
            if ( ! is_null($var_id)) ee()->db->where('vcm.var_id', $var_id);

            if (isset($settings['allowed_groups']) && is_array($settings['allowed_groups']) && ! empty($settings['allowed_groups'])) {
                ee()->db->where_in('m.group_id', $settings['allowed_groups']);
            }

            if (isset($settings['max_selections']) && is_numeric($settings['max_selections']) && $settings['max_selections'] > 0) {
                ee()->db->limit($settings['max_selections']);
            }

            if ( ! is_null($group_by)) {
                ee()->db->group_by($group_by);
            }

            // Handle custom search restrictions
            if (isset($settings['search']) && is_array($settings['search'])) {
                foreach ($settings['search'] AS $field => $values) {
                    if (is_array($values)) {
                        ee()->db->where_in('m.' . $field, $values);
                    } else {
                        ee()->db->where('m.' . $field, $values);
                    }
                }
            }

            if (isset($settings['order_by']) && ! empty($settings['order_by'])) {
                $order_by = $settings['order_by'];
            } else {
                $order_by = 'vcm.order';
            }

            if (isset($settings['sort']) && strtolower($settings['sort']) == 'desc') {
                $sort = 'desc';
            } else {
                $sort = 'asc';
            }

            ee()->db->order_by($order_by, $sort);

            if (isset($settings['limit']) && is_numeric($settings['limit']) && $settings['limit'] > 0) {

                // Make sure we don't conflict with the max_selections
                if ( ! isset($settings['max_selections']) || $settings['max_selections'] == 0) {
                    ee()->db->limit($settings['limit']);
                } else {
                    ee()->db->limit(($settings['limit'] < $settings['max_selections']) ? $settings['limit'] : $settings['max_selections']);
                }

            }

            $this->cache['memberAssociations'][$cache_key] = ee()->db->get()->result_array();
        }

        return $this->cache['memberAssociations'][$cache_key];
    }

    /**
     * Ensure required data is available to save this record
     */
    public function validRecord($record)
    {
        if (isset($record['entry_id']) && ! empty($record['entry_id']) && is_numeric($record['entry_id'])) {
            return true;
        }

        if (isset($record['var_id']) && ! empty($record['var_id']) && is_numeric($record['var_id'])) {
            return true;
        }

        return false;
    }

    /**
     * Return list of valid Member IDs from selected list
     */
    public function validateSelections($selections, $settings)
    {
        ee()->db->select('member_id')
            ->from('members')
            ->where_in('member_id', $selections);

        if (isset($settings['allowed_groups']) && is_array($settings['allowed_groups']) && ! empty($settings['allowed_groups'])) {
            ee()->db->where_in('group_id', $settings['allowed_groups']);
        }

        if (isset($settings['max_selections']) && is_numeric($settings['max_selections']) && $settings['max_selections'] > 0) {
            ee()->db->limit($settings['max_selections']);
        }

        $results = ee()->db->get()
            ->result_array();

        $temp_output = array();
        foreach ($results AS $result) {
            $temp_output[] = $result['member_id'];
        }

        // Rebuild order based on original selection
        $output = array();
        foreach ($selections AS $selection) {
            if (in_array($selection, $temp_output)) {
                $output[] = $selection;
            }
        }

        return $output;
    }

    /**
     * Clear selections from database that are no longer selected
     */
    public function clearOldSelections($selections, $settings)
    {
        // Make general restrictions for this particular field
        ee()->db->where('entry_id', $settings['entry_id'])
            ->where('field_id', $settings['field_id'])
            ->where('col_id', $settings['col_id'])
            ->where('row_id', $settings['row_id'])
            ->where('var_id', $settings['var_id'])
            ->where('is_draft', $settings['is_draft']);

        // Clear everything for this field if no selections are made
        if (empty($selections)) {
            $selections = array(0);
        }

        ee()->db->where_not_in('member_id', $selections)
            ->delete('vmg_chosen_member');

        return ee()->db->affected_rows();
    }

    /**
     * Make draft data live
     */
    public function publishDraft($settings)
    {
        // Delete current live selections
        ee()->db->where('entry_id', $settings['entry_id'])
            ->where('is_draft', 0)
            ->delete('vmg_chosen_member');

        // Make draft selections live
        ee()->db->where('entry_id', $settings['entry_id'])
            ->where('is_draft', 1)
            ->update('vmg_chosen_member', array(
                'is_draft' => 0,
            ));
    }

    /**
     * Remove all draft entries
     */
    public function discardDraft($settings)
    {
        ee()->db->where('entry_id', $settings['entry_id'])
            ->where('is_draft', 1)
            ->delete('vmg_chosen_member');

        return ee()->db->affected_rows();
    }

    /**
     * Remove records for fields/columns/variables that no longer exist
     */
    public function cleanUp()
    {
        // Remove old records for deleted fields
        $field_data = ee()->db->select('vcm.field_id')
            ->from('vmg_chosen_member AS vcm')
            ->join('channel_fields AS cf', 'cf.field_id = vcm.field_id', 'LEFT OUTER')
            ->where('vcm.field_id != 0')
            ->where('cf.field_id IS NULL')
            ->get()
            ->result_array();

        $bad_field_rows = array();
        foreach ($field_data AS $row) {
            $bad_field_rows[] = $row['field_id'];
        }

        if ( ! empty($bad_field_rows)) {
            ee()->db->where_in('field_id', $bad_field_rows)
                ->delete('vmg_chosen_member');
        }

        // Remove old Matrix records
        $matrix_data = ee()->db->select('vcm.row_id')
            ->from('vmg_chosen_member AS vcm')
            ->join('matrix_data AS md', 'md.row_id = vcm.row_id', 'LEFT OUTER')
            ->where('vcm.row_id != 0')
            ->where('(vcm.field_id != 0 OR vcm.var_id != 0)')
            ->where('md.row_id IS NULL')
            ->group_by('vcm.row_id')
            ->get()
            ->result_array();

        $bad_matrix_rows = array();
        foreach ($matrix_data AS $row) {
            $bad_matrix_rows[] = $row['row_id'];
        }

        if ( ! empty($bad_matrix_rows)) {
            ee()->db->where_in('row_id', $bad_matrix_rows)
                ->delete('vmg_chosen_member');
        }

        // Remove old Low Variable records
        $var_data = ee()->db->select('vcm.var_id')
            ->from('vmg_chosen_member AS vcm')
            ->join('global_variables AS gv', 'gv.variable_id = vcm.var_id', 'LEFT OUTER')
            ->where('vcm.var_id != 0')
            ->where('gv.variable_id IS NULL')
            ->group_by('vcm.var_id')
            ->get()
            ->result_array();

        $bad_var_rows = array();
        foreach ($var_data AS $row) {
            $bad_var_rows[] = $row['var_id'];
        }

        if ( ! empty($bad_var_rows)) {
            ee()->db->where_in('var_id', $bad_var_rows)
                ->delete('vmg_chosen_member');
        }
    }

    /**
     * Save current selections to database
     */
    public function saveSelections($selections, $settings)
    {
        // Build base row data
        $data = array(
            $settings['entry_id'],
            $settings['field_id'],
            $settings['col_id'],
            $settings['row_id'],
            $settings['var_id'],
            $settings['is_draft'],
        );

        // Save them all
        foreach ($selections AS $key => $selection) {

            ee()->db->query("INSERT INTO " . ee()->db->dbprefix . "vmg_chosen_member SET entry_id = ?, field_id = ?, col_id = ?, row_id = ?, var_id = ?, is_draft = ?, member_id = ?, `order` = ? ON DUPLICATE KEY UPDATE `order` = ?", array_merge($data, array(
                    $selection,
                    $key,
                    $key,
                )
            ));

        }
    }

    /**
     * Get all available member groups
     */
    public function getMemberGroups()
    {
        return ee()->db->select("mg.group_id, mg.group_title")
            ->from('member_groups AS mg')
            ->group_by('mg.group_id')
            ->get()
            ->result_array();
    }

    /**
     * Get all custom member fields
     */
    public function getCustomMemberFields()
    {
        return ee()->db->select("m_field_id, m_field_name, m_field_label")
            ->from('member_fields')
            ->order_by('m_field_order', 'asc')
            ->get()
            ->result_array();
    }

    /**
     * Get action_id for a specific method
     */
    public function actionId($method, $full_path = false)
    {
        if ( ! isset($this->cache['action'][$method])) {
            $action = ee()->db->select('action_id')
                ->from('actions')
                ->where('class', 'Vmg_chosen_member')
                ->where('method', $method)
                ->get()
                ->row_array();

            $this->cache['action'][$method] = $action;
        }

        if (isset($this->cache['action'][$method]['action_id'])) {

            if ($full_path) {
                return ee()->functions->fetch_site_index(0, 0) . QUERY_MARKER . 'ACT=' . $this->cache['action'][$method]['action_id'];
            }

            return $this->cache['action'][$method]['action_id'];
        }

        return null;
    }

    /**
     * Include the required CSS and JS
     */
    public function includeAssets()
    {
        if ( ! isset($this->cache['assets_included']))
        {
            foreach ($this->buildCss() AS $css) {
                ee()->cp->add_to_head('<link rel="stylesheet" type="text/css" href="' . $css . '" />');
            }

            foreach ($this->buildJs() AS $js) {
                ee()->cp->add_to_foot('<script type="text/javascript" src="' . $js . '"></script>');
            }

            $this->cache['assets_included'] = true;
        }

        return true;
    }

    /**
     * Build CSS files
     */
    public function buildCss()
    {
        return array(
            ee()->config->item('theme_folder_url') . 'third_party/vmg_chosen_member/chosen/chosen.css',
            ee()->config->item('theme_folder_url') . 'third_party/vmg_chosen_member/vmg_chosen_member.css',
        );
    }

    /**
     * Build JS files
     */
    public function buildJs()
    {
        return array(
            ee()->config->item('theme_folder_url') . 'third_party/vmg_chosen_member/chosen/chosen.jquery.js',
            ee()->config->item('theme_folder_url') . 'third_party/vmg_chosen_member/vmg_chosen_member.js',
        );
    }

    /**
     * Prefix array for custom output
     */
    public function setPrefix($array, $prefix = '')
    {
        // Bail now if no prefix set
        if (empty($prefix)) {
            return $array;
        }

        foreach ($array AS $key => $member) {
            foreach ($member AS $item => $value) {

                // Set prefix where applicable
                if (!in_array($item, $this->disallowed_fields)) {
                    $array[$key][$prefix . $item] = $value;
                }

                // Unset pre-prefix field and disallowed fields
                if (!empty($prefix) || in_array($item, $this->disallowed_fields)) {
                    unset($array[$key][$item]);
                }

            }
        }

        return $array;
    }

    /**
     * Remove X number of characters from end of string
     */
    public function backspace($string, $backspace)
    {
        if (is_numeric($backspace) && $backspace > 0) {
            $string = substr($string, 0, ($backspace * -1));
        }

        return $string;
    }

    /**
     * Build base fieldtype data array
     */
    public function initData(&$obj)
    {
        $is_draft = false;
        if ((isset($this->cache['is_draft']) && $this->cache['is_draft']) || (isset(ee()->session->cache['ep_better_workflow']['is_draft']) && ee()->session->cache['ep_better_workflow']['is_draft'])) {
            $is_draft = true;
        }

        $obj->ft_data = array(
            'entry_id' => $this->getSetting($obj, 'entry_id', 0, true),
            'field_name' => $this->getSetting($obj, 'cell_name', 'field_name'),
            'field_id' => $this->getSetting($obj, 'field_id', 0, true),
            'row_id' => $this->getSetting($obj, 'row_id', 0, true),
            'col_id' => $this->getSetting($obj, 'col_id', 0, true),
            'var_id' => $this->getSetting($obj, 'var_id', 0, true),
            'allowed_groups' => $this->getSetting($obj, 'allowed_groups', null, true),
            'max_selections' => $this->getSetting($obj, 'max_selections', null, true),
            'placeholder_text' => $this->getSetting($obj, 'placeholder_text', null, true),
            'search_fields' => $this->getSetting($obj, 'search_fields', null, true),
            'is_draft' => ($is_draft ? 1 : 0),
        );

        $obj->ft_data['cache_key'] = md5("{$obj->ft_data['entry_id']}_{$obj->ft_data['field_id']}_{$obj->ft_data['col_id']}_{$obj->ft_data['var_id']}");

        return $obj->ft_data;
    }

    /**
     * Return settings value by auto handling fallbacks
     */
    public function getSetting(&$obj, $name, $fallback, $literal_fallback = false)
    {
        // Try to locate the setting
        if (isset($obj->settings[$name])) {
            return $obj->settings[$name];
        } elseif (isset($obj->row[$name])) {
            return $obj->row[$name];
        } elseif (isset($obj->$name)) {
            return $obj->$name;
        } elseif (isset($_POST[$name]) || isset($_GET[$name])) {
            return ee()->input->get_post($name);
        }

        // Handle fallback
        if ($literal_fallback) {
            return $fallback;
        } else {
            return $this->getSetting($obj, $fallback, false, true);
        }

        return false;
    }

    /**
     * Returns simple preview of a string with formatting removed
     */
    public function cleanFieldPreview($text, $search, $max_length = 25)
    {
        if (strlen($text) > $max_length) {
            $text = preg_replace('/[^[:alnum:][:punct:] ]/', '', $text);
            $find_string = stripos($text, $search);
            $text = substr($text, $find_string - $max_length, strlen($search) + ($max_length*2));

            $text = '...' . $text . '...';
        }

        return '<i>' . $text . '</i>';
    }

    /**
     * Get field settings array
     */
    public function fieldSettings($field_id, $col_id = 0, $var_id = 0)
    {
        if (is_numeric($col_id) && $col_id > 0) {

            // Matrix column settings
            ee()->db->select('mc.col_settings AS setting_data')
                ->from('matrix_cols AS mc');

            if (is_numeric($var_id) && $var_id > 0) {
                ee()->db->where('mc.var_id', $var_id);
            } else {
                ee()->db->where('mc.field_id', $field_id);
            }

            $settings = ee()->db->where('mc.col_id', $col_id)
                ->where('mc.col_type', 'vmg_chosen_member')
                ->get()
                ->row_array();

        } elseif (is_numeric($var_id) && $var_id > 0) {

            // Low variable settings
            $settings = ee()->db->select('lv.variable_type, lv.variable_settings AS setting_data')
                ->from('low_variables AS lv')
                ->where('lv.variable_id', $var_id)
                ->where('lv.variable_type', 'vmg_chosen_member')
                ->get()
                ->row_array();

        } else {

            // Standard field settings
            $settings = ee()->db->select('cf.field_settings AS setting_data')
                ->from('channel_fields AS cf')
                ->where('cf.field_id', $field_id)
                ->where('cf.field_type', 'vmg_chosen_member')
                ->get()
                ->row_array();

        }

        if (isset($settings['setting_data'])) {
            return unserialize(base64_decode($settings['setting_data']));
        }

        return false;
    }

    /**
     * Get all custom member fields
     */
    public function customMemberFields()
    {
        return ee()->db->select("m_field_id, m_field_name, m_field_label")
            ->from('member_fields')
            ->order_by('m_field_order', 'asc')
            ->get()
            ->result_array();
    }

    /**
     * Member autocomplete results
     */
    public function memberAutoComplete($settings, $search_fields, $search_fields_where)
    {
        return ee()->db->select('m.member_id, m.username, m.screen_name, ' . implode($search_fields, ', '))
            ->from('members AS m')
            ->join('member_data AS md', 'md.member_id = m.member_id', 'left')
            ->where_in('m.group_id', $settings['allowed_groups'])
            ->where('(' . implode(' OR ', $search_fields_where) . ')')
            ->limit(50)
            ->get()
            ->result_array();
    }

    /**
     * Convert field/column name to ID
     */
    public function convertFieldName($field_name, $column_name = false)
    {
        ee()->db->select("cf.field_id AS field_id, mc.col_id, IF(mc.col_id IS NULL, cf.field_name, mc.col_name) AS field_name", false)
            ->from('channel_fields AS cf')
            ->join('matrix_cols AS mc', 'mc.field_id = cf.field_id', 'left')
            ->where("((cf.field_type = 'vmg_chosen_member' || cf.field_type = 'matrix') AND cf.field_name = " . ee()->db->escape($field_name) . ")");

        if ( ! empty($column_name)) {
            ee()->db->where("(cf.field_type = 'matrix' AND mc.col_type = 'vmg_chosen_member' AND mc.col_name = " . ee()->db->escape($column_name) . ")");
        } else {
            ee()->db->where('cf.field_type', 'vmg_chosen_member');
        }

        $field = ee()->db->get()->row_array();

        return array(
            'field_id' => (isset($field['field_id']) ? $field['field_id'] : null),
            'col_id' => (isset($field['col_id']) ? $field['col_id'] : null),
            'field_name' => (isset($field['field_name']) ? $field['field_name'] : null),
        );
    }

    /**
     * Get Channel Entries containing member
     */
    public function associatedChannelEntries($field_id, $col_id, $member_ids)
    {
        $col_id = (is_numeric($col_id) && $col_id > 0) ? $col_id : 0;
        $results = array();

        // Handle CURRENT_MEMBER option if set
        if (in_array('CURRENT_MEMBER', $member_ids) && ee()->session->userdata('member_id') > 0) {
            $member_ids[] = ee()->session->userdata('member_id');
            unset($member_ids[array_search('CURRENT_MEMBER', $member_ids)]);
        }

        // Handle OR_EMPTY option for standard field
        if (in_array('OR_EMPTY', $member_ids)) {

            // Get channel info
            $channel_data = ee()->db->select('c.channel_id')
                ->from('channel_fields AS cf')
                ->join('channels AS c', 'c.field_group = cf.group_id', 'inner')
                ->where('cf.field_id', $field_id)
                ->group_by('c.channel_id')
                ->get()
                ->result_array();

            $channels = array(0);
            foreach ($channel_data AS $channel) {
                $channels[] = $channel['channel_id'];
            }

            $data = ee()->db->select('ct.entry_id')
                ->from('channel_titles AS ct')
                ->join('vmg_chosen_member AS vcm', 'vcm.entry_id = ct.entry_id AND vcm.field_id = ' . ee()->db->escape($field_id) . ' AND vcm.col_id = ' . ee()->db->escape($col_id), 'LEFT OUTER')
                ->where_in('ct.channel_id', $channels)
                ->where('vcm.entry_id IS NULL')
                ->get()
                ->result_array();

            foreach ($data AS $entry) {
                $results[] = $entry['entry_id'];
            }

            unset($member_ids[array_search('OR_EMPTY', $member_ids)]);
        }

        $data = ee()->db->select('vcm.entry_id')
            ->from('vmg_chosen_member AS vcm')
            ->where('vcm.field_id', $field_id)
            ->where('vcm.col_id', $col_id)
            ->where_in('vcm.member_id', $member_ids)
            ->get()
            ->result_array();

        foreach ($data AS $entry) {
            $results[] = $entry['entry_id'];
        }

        return $results;
    }

}
