<?php
/**
* Pandora v1
* @license GPLv3 - http://www.opensource.org/licenses/GPL-3.0
* @copyright (c) 2012 KDE. All rights reserved.
*/

class skin
{
    // Class wide variables
    var $skin_name;
    var $skin_name_fancy;
    var $skin_path;
    var $skin_vars;
    var $skin_title;
    var $skin_script;
    var $skin_file;

    // Class constructor
    function __construct()
    {
        global $core, $config;
       
        $this->skin_name = strtolower($config->skin_name);
        $this->skin_name_fancy = $config->skin_name;
        $this->skin_vars = array();
        $this->skin_script = array();
        $this->skin_file = '';
        $this->skin_path = $core->path() . 'skins/' . strtolower($config->skin_name);
    }

    // Returns the name of the active skin
    function name()
    {
        return $this->skin_name;
    }

    // Function to initialize a skin file
    function init($file)
    {
        $this->skin_file = $file;
    }

    // Function to assign template variables
    function assign($data, $value = "")
    {
        if (!is_array($data) && $value)
        {
            $this->skin_vars[$data] = $value;
        }
        else
        {
            foreach ($data as $key => $value)
            {
                $this->skin_vars[$key] = $value;
            }
        }
    }

    // Function to set the page title
    function title($value)
    {
        $this->skin_title = $value;
    }

    // Function to parse template variables
    function parse($file_name, $has_scripts = false)
    {
        global $lang, $gsod;

        // First, see if scripts are added
        if ($has_scripts)
        {
            $tmp_data = ' ';

            foreach($this->skin_script as $script)
            {
                $tmp_data .= "\n" . '<script type="text/javascript">' . "\n";
                $tmp_data .= file_get_contents($script);
                $tmp_data .= "\n</script>\n";

                foreach($this->skin_vars as $key => $value)
                {
                    $tmp_data = str_replace("[[$key]]", $value, $tmp_data);
                }

                $tmp_data = preg_replace('#/\*(?:[^*]*(?:\*(?!/))*)*\*/#', '', $tmp_data);
            }

            $data = $tmp_data;
            unset($tmp_data);
        }

        // Parse template variables
        if (!file_exists($file_name))
        {
            $message  = '<b>Pandora skin read error</b><br /><br />';
            $message .= 'Error: Skin file not found<br />';
            $message .= 'Verify that the skin selected is present in the skins/ folder';
            $gsod->trigger($message);
        }
        
        $data = ($has_scripts ? $data : '') . file_get_contents($file_name);
        $data = $this->set_defaults($data);

        foreach($this->skin_vars as $key => $value)
        {
            $data = str_replace("[[$key]]", $value, $data);
        }

        // Remove unknown placeholders
        $data = preg_replace('/\[\[(.*?)\]\]/', '', $data);
        
        // Apply localization data
        $data = $lang->parse($data);

        // Done!
        return trim($data);
    }

    // Function to assign default variables
    function set_defaults($data)
    {
        global $core, $auth, $lang, $nav;

        $data = str_replace("[[site_logo]]",
                            $this->skin_path . '/images/' . $lang->lang_name . '/logo.png', $data);
        $data = str_replace("[[site_logo_rss]]",
                            $core->base_uri() . 'skins/' . $this->skin_name .
                            '/images/' . $lang->lang_name . '/logo_rss.png', $data);
        $data = str_replace("[[page_title]]", $this->skin_title, $data);
        $data = str_replace("[[skin_path]]", $this->skin_path, $data);
        $data = str_replace("[[skin_name]]", $this->skin_name_fancy, $data);
        $data = str_replace("[[username]]", $auth->username, $data);
        $data = str_replace("[[guest_visibility]]", $this->visibility(!$auth->is_logged_in), $data);
        $data = str_replace("[[user_visibility]]", $this->visibility($auth->is_logged_in), $data);
        $data = str_replace("[[admin_visibility]]", $this->visibility($auth->is_admin), $data);
        $data = str_replace("[[nav_home]]", $core->path(), $data);
        
        return $data;
    }

    // Function to add a script
    function script($file_name)
    {
        global $mode;

        if (!$mode)
        {
            $this->skin_script[] = realpath('skins/' . $this->skin_name . '/js/' . $file_name);
        }
    }

    // Function to get full path of file
    function locate($file)
    {
        global $core;
        
        if (strpos($file, '.html') === false &&
            strpos($file, '.xml') === false &&
            strpos($file, '.json') === false)
        {
            $file .= '.html';
        }

        if (strpos($file, 'api') !== false && strpos($file, 'api') == 0)
        {
            return realpath('api/' . $file);
        }
        else if (strpos($file, 'rss') !== false && strpos($file, 'rss') == 0)
        {
            return realpath('rss/' . $file);
        }
        else
        {
            return realpath('skins/' . $this->skin_name . '/html/' . $file);
        }
    }

    // Function to output the page
    function output($file = false, $body_only = false)
    {
        global $core, $gsod;

        if ($file)
        {
            $file = $this->locate($file);

            // Return the parsed template
            return $this->parse($file);
        }
        else
        {
            $file_header = $this->locate('tpl_header');
            $file_footer = $this->locate('tpl_footer');

            if (!$this->skin_file)
            {
                $message  = '<b>Pandora skin parse error</b><br /><br />';
                $message .= 'Error: Skin file not initialized<br />';
                $message .= 'Use $skin->init(\'filename\') to load a skin file';
                $gsod->trigger($message);
            }

            $file_body = $this->locate($this->skin_file);

            if ($body_only)
            {
                echo $this->parse($file_body, true);
            }
            else
            {
                echo $this->parse($file_header);
                echo $this->parse($file_body, true);
                echo $this->parse($file_footer);
            }
        }
    }

    // Function to generate pagination
    function pagination($total_items, $current_page)
    {
        global $lang, $core;

        $pages = ceil($total_items / 30);
        $pagination = '';

        for ($idx = 1; $idx <= $pages; $idx++)
        {
            if ($pages > 10 && $idx > 3 && $idx != ($current_page - 1) &&
                $idx != ($current_page) && $idx != ($current_page + 1) &&
                $idx < ($pages - 2))
            {
                $pagination .= ' ...';

                if ($idx < ($current_page - 1))
                {
                    $idx = $current_page - 2;
                }
                else
                {
                    $idx = $pages - 3;
                }
            }
            else
            {
                if ($idx != $current_page)
                {
                    $pagination .= '<a href="&pg=' . $idx . '">';
                }

                $pagination .= '<span class="page_no';
                $pagination .= ($idx == $current_page ? ' page_current' : '');
                $pagination .= '">' . $idx . '</span>';

                if ($idx != $current_page)
                {
                    $pagination .= "</a>";
                }
            }
        }

        return $pagination;
    }

    // Creates a list of options from directory contents
    function get_list($relative_path, $excluded_files = "", $selected_entry = false, $pascal_case = false, $trim_extension = false)
    {
        $dir = opendir(realpath($relative_path));
        $list = '';
        $entries = array();

        if (!is_array($excluded_files))
        {
            $excluded_files = array($excluded_files);
        }

        while ($entry = readdir($dir))
        {
            if ($entry != '.' && $entry != '..' && !in_array($entry, $excluded_files))
            {
                if ($trim_extension)
                {
                    $entry = substr($entry, 0, strrpos($entry, '.'));
                }
                
                if ($pascal_case)
                {
                    $entries[] = strtoupper(substr($entry, 0, 1)) . substr($entry, 1, strlen($entry) - 1);
                }
                else
                {
                    $entries[] = $entry;
                }
            }
        }

        sort($entries);

        foreach($entries as $entry)
        {
            $selected = ($selected_entry !== false && strtolower($entry) == strtolower($selected_entry));
            $list .= '<option' . ($selected ? ' selected="selected"' : '') . '>' .
                     $entry . '</option>';
        }

        return $list;
    }

    // Function to prematurely end a session
    function kill()
    {
        global $lang;

        $this->title($lang->get('error'));
        $this->output();
        exit;
    }

    // Return visibility based on condition
    function visibility($condition)
    {
        return $condition ? 'visible' : 'hidden';
    }
    
    // Function to exclude a string from being treated as a key
    function escape(&$data)
    {
        $data = preg_replace('/\[\[(.*?)\]\]/', '[' . chr(0) . '[$1]' . chr(0) . ']', $data);
    }
}

?>