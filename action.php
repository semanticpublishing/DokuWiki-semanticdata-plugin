<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
require_once(DOKU_PLUGIN.'action.php');

require_once DOKU_PLUGIN.'semanticdata/bureaucracy_field.php';

class action_plugin_semanticdata extends DokuWiki_Action_Plugin {

    /**
     * will hold the semanticdata helper plugin
     */
    var $dthlp = null;

    /**
     * Constructor. Load helper plugin
     */
    function action_plugin_semanticdata(){
        $this->dthlp =& plugin_load('helper', 'semanticdata');
    }

    /**
     * Registers a callback function for a given event
     */
    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'BEFORE', $this, '_handle');
        $controller->register_hook('HTML_SECEDIT_BUTTON', 'BEFORE', $this, '_editbutton');
        $controller->register_hook('HTML_EDIT_FORMSELECTION', 'BEFORE', $this, '_editform');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, '_handle_edit_post');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, '_handle_ajax');
    }

    /**
     * Handles the page write event and removes the database info
     * when the plugin code is no longer in the source
     */
    function _handle(&$event, $param){
        $data = $event->data;
        if(strpos($data[0][1],'dataentry') !== false) return; // plugin seems still to be there

        $store = $this->dthlp->_getTripleStore();
        $resultFormat = phpSesame::SPARQL_XML; // The expected return type, will return a phpSesame_SparqlRes object (Optional)
		$lang = "sparql"; // Can also choose SeRQL (Optional)
		$infer = true; // Can also choose to explicitly disallow inference. (Optional)
        
		if(!$store) {
        	msg('Connection to triple store not found',-1); 
        	return;
        } 
        $id = ltrim($data[1].':'.$data[2],':');

        $sparql = 
			sprintf('DELETE DATA { <%s%s> ?s ?o }',$this->getConf('base_url'),urlencode($id));
		$result = $store->update($sparql, $resultFormat, $lang, $infer);
    }

    function _editbutton(&$event, $param) {
        if ($event->data['target'] !== 'plugin_semanticdata') {
            return;
        }

        $event->data['name'] = $this->getLang('dataentry');
    }

    function _editform(&$event, $param) {
        global $TEXT;
        if ($event->data['target'] !== 'plugin_semanticdata') {
            // Not a data edit
            return;
        }

        $event->stopPropagation();
        $event->preventDefault();
        unset($event->data['intro_locale']);
        $event->data['media_manager'] = false;

        echo $this->locale_xhtml('edit_intro' . ($this->getConf('edit_content_only') ? '_contentonly' : ''));

        require_once 'renderer_semanticdata_edit.php';
        $Renderer = new Doku_Renderer_plugin_semanticdata_edit();
        $Renderer->form = $event->data['form'];

        // Loop through the instructions
        $instructions = p_get_instructions($TEXT);
        foreach ( $instructions as $instruction ) {
            // Execute the callback against the Renderer
            call_user_func_array(array(&$Renderer, $instruction[0]),$instruction[1]);
        }
    }

    function _handle_edit_post($event) {
        if (!isset($_POST['data_edit'])) {
            return;
        }
        global $TEXT;

        require_once 'syntax/entry.php';
        $TEXT = syntax_plugin_semanticdata_entry::editToWiki($_POST['data_edit']);
    }

    function _handle_ajax($event) {
        if (strpos($event->data, 'data_page_') !== 0) {
            return;
        }
        $event->preventDefault();

        $type = substr($event->data, 10);
        $aliases = $this->dthlp->_aliases();
        if (!isset($aliases[$type])) {
            echo 'Unknown type';
            return;
        }
        if ($aliases[$type]['type'] !== 'page') {
            echo 'AutoCompletion is only supported for page types';
            return;
        }

        if (substr($aliases[$type]['postfix'], -1, 1) === ':') {
            // Resolve namespace start page ID
            global $conf;
            $aliases[$type]['postfix'] .= $conf['start'];
        }

        $search = $_POST['search'];
        $pages = ft_pageLookup($search, false, false);

        $regexp = '/^';
        if ($aliases[$type]['prefix'] !== '') {
            $regexp .= preg_quote($aliases[$type]['prefix'], '/');
        }
        $regexp .= '([^:]+)';
        if ($aliases[$type]['postfix'] !== '') {
            $regexp .= preg_quote($aliases[$type]['postfix'], '/');
        }
        $regexp .= '$/';

        $result = array();
        foreach ($pages as $page => $title) {
            $id = array();
            if (!preg_match($regexp, $page, $id)) {
                // Does not satisfy the postfix and prefix criteria
                continue;
            }

            $id = $id[1];

            if ($search !== '' &&
                stripos($id, cleanID($search)) === false &&
                stripos($title, $search) === false) {
                // Search string is not in id part or title
                continue;
            }

            if ($title === '') {
                $title = utf8_ucwords(str_replace('_', ' ', $id));
            }
            $result[hsc($id)] = hsc($title);
        }

        $json = new JSON();
        echo '(' . $json->encode($result) . ')';
    }
}
