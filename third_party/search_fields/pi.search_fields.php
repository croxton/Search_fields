<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

$plugin_info = array(
  'pi_name' => 'Search Fields',
  'pi_version' =>'2.0.3',
  'pi_author' =>'Mark Croxton',
  'pi_author_url' => 'http://www.hallmark-design.co.uk/',
  'pi_description' => 'Search channel entry titles, custom fields, category names, category descriptions and category custom fields.',
  'pi_usage' => Search_fields::usage()
  );

/**
 * Search Fields
 *
 * Searches entry titles and custom fields within
 * channel entries and categories for keywords/phrase.
 * Outputs a delimited list of entry ids to a placeholder
 *
 * @version 2.0.3
 *
 */
class Search_fields {

	private $_custom_fields = array();
	private $_cat_fields = array();
	private $_ignore = array();
	public $return_data = '';
	public $min_length = 3;

	/**
	 * Constructor
	 * @access public
	 * @return void
	 */
	function Search_fields()
	{
		$this->EE =& get_instance();

		$this->EE->lang->loadfile('search');

		// words to ignore in search terms
		include(APPPATH.'config/stopwords'.EXT);
		$this->_ignore = $ignore;

		$channel 	= $this->EE->TMPL->fetch_param('channel', '*');
		$delimiter 	= $this->EE->TMPL->fetch_param('delimiter', '|');
		$operator 	= strtoupper($this->EE->TMPL->fetch_param('operator'));

		//default operator is OR
		$operator = $operator ? $operator : 'OR';

		$ph 		= $this->EE->TMPL->fetch_param('placeholder', 'search_results');
		$site 		= $this->EE->TMPL->fetch_param('site', $this->EE->config->item('site_id'));
		$this->min_length = $this->EE->TMPL->fetch_param('min_length', $this->min_length);

		// fetch the tagdata
		$tagdata = $this->EE->TMPL->tagdata;

		// get available custom fields
		$this->_fetch_custom_channel_fields();

		// some initial defaults
		$sql_conditions = '';
		$search_cat = $search_cat_fields = false;

		// grab any posted search parameters and stick in an array where field => search phrase
		if ($this->EE->TMPL->fetch_param('dynamic_parameters') !== FALSE)
		{
			foreach (explode('|', $this->EE->TMPL->fetch_param('dynamic_parameters')) as $var)
			{
				if (strncmp($var, 'search:', 7) == 0)
				{
					$this->EE->TMPL->search_fields[substr($var, 7)] = $this->EE->input->post($var);
				}
			}
		}

		/** ---------------------------------------
    	//  Field searching
		//  => The Template parser stores parameters that start
		//  => with 'search:' in a special array, search_fields[]
    	/** ---------------------------------------*/
		if (! empty($this->EE->TMPL->search_fields))
		{
			foreach ($this->EE->TMPL->search_fields as $field_name => $terms)
			{
				$field_id = null;
				$search_grid = false;

				$grid_column = null;

				//We've got ourselves a grid field
				if (strpos($field_name, ':') !== FALSE) {
					$grid_column = substr($field_name, strpos($field_name, ':') + 1);
					$field_name = substr($field_name, 0, strpos($field_name, ':'));

					$search_grid = true;
				}

				// search channel custom fields
				if (isset($this->_custom_fields[$this->EE->config->item('site_id')][$field_name]))
				{
					$field_id = $this->_custom_fields[$this->EE->config->item('site_id')][$field_name];

					$field_sql = 'wd.field_id_'.$field_id;

					// get field_type
					$sql = "SELECT field_type
							FROM exp_channel_fields
							WHERE field_id = {$field_id}";

					$query = $this->EE->db->query($sql);

					if ($query->num_rows > 0) {
						foreach ($query->result_array() as $row) {
							$field_type = $row["field_type"];
						}
					}

					// search grid custom fields
					if ($field_type == "grid")
					{
						if ($grid_column) {
							//Find grid column searched for
							$results = $this->EE->db->select('col_id')
							->from('grid_columns c')
							->where(['c.col_name' => $grid_column])
							->limit(1)
							->get();

							if ($results->num_rows() > 0) {
								$grid_column_id = $results->result_array()[0]["col_id"];
								$field_sql = "wg.col_id_{$grid_column_id}";
							} else {
								$field_sql = '';
							}

						} else {
							$field_sql = '';
						}
					}

					// Tagger module support
					if (strncmp($terms, 'tagger=', 7) ==  0)
					{
						$tag = substr($terms, 7);

						// Grab all entries with this tag
						// Note that it doesn't matter what the custom field containing the tags is called
						// Tagger's data model permits only one tag field per entry
						$this->EE->db->select('tl.item_id');
						$this->EE->db->from('exp_tagger_links tl');
						$this->EE->db->join('exp_tagger t', 't.tag_id = tl.tag_id', 'left');
						$this->EE->db->join('exp_channel_titles ct', 'ct.entry_id = tl.item_id', 'left');
						$this->EE->db->where('t.tag_name', $tag);

						// Fetch
						$query = $this->EE->db->get();

						if ($query->num_rows() > 0)
						{
							$matched_entry_sql = '';
							foreach ($query->result() as $row)
							{
								$matched_entry_sql .= "'".$row->item_id."',";
							}
							$matched_entry_sql = rtrim($matched_entry_sql, ',');

							// now add to our master search query...
							$sql_conditions = "AND wt.entry_id IN({$matched_entry_sql})";
						}
					}
				}

				// search channel titles
				else if ($field_name =="title")
				{
					$field_sql = 'wt.title';
				}

				// search category titles
				else if ($field_name =="cat_name")
				{
					$field_sql = 'ct.cat_name';
					$search_cat = true;
				}

				// search category description
				else if ($field_name =="cat_description")
				{
					$field_sql = 'ct.cat_description';
					$search_cat = true;
				}

				// search category custom fields
				else if (!!strstr($field_name,'cat_'))
				{
					// get available custom category fields
					$this->_fetch_custom_category_fields();

					if (isset($this->_cat_fields[$this->EE->config->item('site_id')][ltrim($field_name,'cat_')]))
					{
						$field_sql = 'cd.field_id_'.$this->_cat_fields[$this->EE->config->item('site_id')][ltrim($field_name,'cat_')];
						$search_cat_fields = true;
						$search_cat = true;
					}
				}

				// can't search this field because it doesn't exist
				else
				{
					$field_sql = '';
				}

				if ($field_sql !== '' && $terms !== '' )
				{

					if (strncmp($terms, '=', 1) ==  0)
					{
						/** ---------------------------------------
						/**  Exact Match e.g.: search:body="=pickle"
						/** ---------------------------------------*/

						$terms = substr($terms, 1);

						// special handling for IS_EMPTY
						if (strpos($terms, 'IS_EMPTY') !== FALSE)
						{
							$terms = str_replace('IS_EMPTY', '', $terms);
							$terms = $this->_sanitize_search_terms($terms, TRUE);

							$add_search = $this->EE->functions->sql_andor_string($terms, $field_sql);

							// remove the first AND output by $this->EE->functions->sql_andor_string() so we can parenthesize this clause
							$add_search = substr($add_search, 3);

							$conj = ($add_search != '' && strncmp($terms, 'not ', 4) != 0) ? 'OR' : 'AND';

							if (strncmp($terms, 'not ', 4) == 0)
							{
								$sql_conditions .= $operator.' ('.$add_search.' '.$conj.' '.$field_sql.' != "") ';
							}
							else
							{
								$sql_conditions .= $operator.' ('.$add_search.' '.$conj.' '.$field_sql.' = "") ';
							}
						}
						else
						{
							$condition = $this->EE->functions->sql_andor_string($terms, $field_sql).' ';
							// replace leading AND/OR with desired operator
							$condition =  preg_replace('/^AND|OR/', $operator, $condition,1);
							$sql_conditions.=$condition;
						}
					}
					else
					{
						/** ---------------------------------------
						/**  "Contains" e.g.: search:body="pickle"
						/** ---------------------------------------*/

						if (strncmp($terms, 'not ', 4) == 0)
						{
							$terms = substr($terms, 4);
							$like = 'NOT LIKE';
						}
						else
						{
							$like = 'LIKE';
						}

						if (strpos($terms, '&&') !== FALSE)
						{
							$terms = explode('&&', $terms);
							$andor = (strncmp($like, 'NOT', 3) == 0) ? 'OR' : 'AND';
						}
						else
						{
							$terms = explode('|', $terms);
							$andor = (strncmp($like, 'NOT', 3) == 0) ? 'AND' : 'OR';
						}

						$sql_conditions .= ' '.$operator.' (';

						foreach ($terms as $term)
						{
							if ($term == 'IS_EMPTY')
							{
								$sql_conditions .= ' '.$field_sql.' '.$like.' "" '.$andor;
							}
							elseif (strpos($term, '\W') !== FALSE) // full word only, no partial matches
							{
								$not = ($like == 'LIKE') ? ' ' : ' NOT ';
								$term = $this->_sanitize_search_terms($term, TRUE);
								$term = '([[:<:]]|^)'.addslashes(preg_quote(str_replace('\W', '', $term))).'([[:>:]]|$)';
								$sql_conditions .= ' '.$field_sql.$not.'REGEXP "'.$this->EE->db->escape_str($term).'" '.$andor;
							}
							else
							{
								$term = $this->_sanitize_search_terms($term);
								$sql_conditions .= ' '.$field_sql.' '.$like.' "%'.$this->EE->db->escape_like_str($term).'%" '.$andor;
							}
						}

						$sql_conditions = substr($sql_conditions, 0, -strlen($andor)).') ';
					}
				} // <- END if (strncmp($terms, '=', 1) ==  0)
			} // <- END foreach ($this->EE->TMPL->search_fields as $field_name => $terms)
		}  // <- END if (! empty($this->EE->TMPL->search_fields))

		// check that we actually have some conditions to match
		if ($sql_conditions == '')
		{
			// no valid fields to search
			$this->return_data = $this->EE->TMPL->no_results();
			return; // end the process here
		}

		// remove leading AND/OR. Clumsy - find a better way to do this?
		$sql_conditions = preg_replace('/AND|OR/', '', $sql_conditions,1);

		// limit to a channel?
		if ($channel !== '*')
        {
            if(strpos($channel, '|') !== FALSE)
            {
                $channels = explode('|', $channel);
                $sql_conditions = "(".$sql_conditions.") AND (";
                foreach($channels as $ch)
                {
                    $sql_conditions .= "wl.channel_name = '{$this->EE->db->escape_str($ch)}' OR ";
                }
                $sql_conditions = substr($sql_conditions, 0, -4);
                $sql_conditions .= ")";
            }
            else
            {
                $sql_conditions = "(".$sql_conditions.") AND wl.channel_name = '{$this->EE->db->escape_str($channel)}'";
            }
        }

		// let's build the query
		$sql="SELECT distinct(wt.entry_id)
		FROM exp_channel_titles AS wt
		LEFT JOIN exp_channel_data AS wd
		ON wt.entry_id = wd.entry_id
		LEFT JOIN exp_channels AS wl
		ON wt.channel_id = wl.channel_id
		";

		// grids are saved in a different table instead of a column
		if ($search_grid) {
			$sql .= "LEFT JOIN exp_channel_grid_field_{$field_id} AS wg
			ON wt.entry_id = wg.entry_id";
		}

		// join category tables, but only if required
		if ($search_cat)
		{
			$sql .="LEFT JOIN exp_category_posts as cp
					ON wt.entry_id = cp.entry_id
					LEFT JOIN exp_categories as ct
					ON cp.cat_id = ct.cat_id
					";

			// join category field table, again only if required
			if ($search_cat_fields)
			{
				$sql .="LEFT JOIN exp_category_field_data as cd
				ON ct.cat_id = cd.cat_id
				";
			}
		}

		// limit search to current site
		$sql .= " WHERE wt.site_id = {$site} "."\n";

		// add search conditions
		$sql = $sql.'AND ('.$sql_conditions.')';

		$results = $this->EE->db->query($sql);

		// run the query
		if ($results->num_rows() == 0)
        {
			// no results
            $this->return_data = $this->EE->TMPL->no_results();
        }
   		else
		{
        	// loop through found entries
	  		$found_ids = '';
	        foreach($results->result_array() as $row)
	        {
				$found_ids .= ($found_ids=='' ? '' : $delimiter).$row['entry_id'];
			}

			$tagdata = $this->EE->TMPL->swap_var_single($ph, $found_ids, $tagdata);

			// return data
			$this->return_data = $tagdata;
		}
	}

	/**
	 * Fetches custom channel fields from page flash cache.
	 * If not cached, runs query and caches result.
	 * @access private
	 * @return boolean
	 */
	private function _fetch_custom_channel_fields()
    {
		// as standard custom field data is used/stored in exactly the same way by the channel module
		// we'll use the 'channel' class name as the cache key to avoid redundancy
		if (isset($this->EE->session->cache['channel']['custom_channel_fields']))
		{
			$this->_custom_fields = $this->EE->session->cache['channel']['custom_channel_fields'];
			return true;
		}

        // not found so cache them
        $sql = "SELECT field_id, field_type, field_name, site_id
        		FROM exp_channel_fields
				WHERE field_type != 'date'
				AND field_type != 'rel'";

        $query = $this->EE->db->query($sql);

      	if ($query->num_rows > 0)
        {
        	foreach ($query->result_array() as $row)
	        {
	        	// assign standard custom fields
	            $this->_custom_fields[$row['site_id']][$row['field_name']] = $row['field_id'];
	        }
	  		$this->EE->session->cache['channel']['custom_channel_fields'] = $this->_custom_fields;
			return true;
		}
		else
		{
			return false;
		}
    }

	/**
	 * Fetches custom category fields from page flash cache.
	 * If not cached, runs query and caches result.
	 * @access private
	 * @return boolean
	 */
	private function _fetch_custom_category_fields()
    {
		if (isset($this->EE->session->cache['search_fields']['custom_category_fields']))
		{
			$this->_cat_fields = $this->EE->session->cache['search_fields']['custom_category_fields'];
			return true;
		}

		// not found so cache them
		$sql = "SELECT field_id, field_name, site_id
        		FROM exp_category_fields";

        $query = $this->EE->db->query($sql);

		if ($query->num_rows > 0)
        {
        	foreach ($query->result_array() as $row)
	        {
				// assign standard fields
	            $this->_cat_fields[$row['site_id']][$row['field_name']] = $row['field_id'];
				return true;
			}
			$this->EE->session->cache['search_fields']['custom_category_fields'] = $this->_cat_fields;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Sanitize earch terms
	 *
	 * @access private
	 * @param string $keywords
	 * @param boolean $exact_keyword
	 * @return boolean
	 */
	private function _sanitize_search_terms($keywords, $exact_keyword = false)
	{
		/** ----------------------------------------
		/**  Strip extraneous junk from keywords
		/** ----------------------------------------*/
		if ($keywords != "")
		{
			// Load the search helper so we can filter the keywords
			$this->EE->load->helper('search');

			$keywords = sanitize_search_terms($keywords);

			/** ----------------------------------------
			/**  Is the search term long enough?
			/** ----------------------------------------*/

			if (strlen($keywords) < $this->min_length)
			{
				$text = $this->EE->lang->line('search_min_length');

				$text = str_replace("%x", $this->min_length, $text);

				return $this->EE->output->show_user_error('general', array($text));
			}

			// Load the text helper
			$this->EE->load->helper('text');

			$keywords = ($this->EE->config->item('auto_convert_high_ascii') == 'y') ? ascii_to_entities($keywords) : $keywords;


			/** ----------------------------------------
			/**  Remove "ignored" words
			/** ----------------------------------------*/

			if (!$exact_keyword)
			{
				$parts = explode('"', $keywords);

				$keywords = '';

				foreach($parts as $num => $part)
				{
					// The odd breaks contain quoted strings.
					if ($num % 2 == 0)
					{
						foreach ($this->_ignore as $badword)
						{
							$part = preg_replace("/\b".preg_quote($badword, '/')."\b/i","", $part);
						}
					}
					$keywords .= ($num != 0) ? '"'.$part : $part;
				}

				if (trim($keywords) == '')
				{
					return $this->EE->output->show_user_error('general', array($this->EE->lang->line('search_no_stopwords')));
				}
			}
		}

		// finally, double spaces
		$keywords = str_replace("  ", " ", $keywords);

		return $keywords;
	}

	// usage instructions
	function usage()
	{
  		ob_start();
		?>
		-------------------
		HOW TO USE
		-------------------
		Use this plugin to search channel entry titles, custom fields, category names, category descriptions and category custom fields.

		Search parameter syntax is identical to the channel search parameter, see:
		http://expressionengine.com/docs/modules/channel/parameters.html#par_search

		In addition to entry custom fields you can search entry titles, category names, category descriptions and category custom fields. When searching categories references to category fields should be prefixed with 'cat_'.

		For example:
		search:cat_name="keyword"
		search:cat_description="keyword"
		search:cat_custom_field="keyword"

		Returns a delimited list of entry ids.


		Parameters
		----------------

		search:[field]  	=	(optional) Field can be title, cat_name, cat_description, [custom_field_name], cat_[custom_field_name].

		channel				=	(optional) Single channel name to search. Default is * (searches all channels).

		operator        	=	(optional) 'AND' or 'OR'. Operator for joining search field WHERE conditions. Default is 'OR'.

		delimiter			=	(optional) Delimiter for returned entry id string. Default is pipe |.

		placeholder			=	(optional) Single variable placeholder to replace with search results output. Default is search_results (use as {search_results}).

		site				= 	(optional) The site id. Default is current site id.

		min_length			= 	(optional) The minimum length for the search term. Default is 3.

		dynamic_parameters	=	(optional) Allow specific search parameters to set via $_POST. E.g. "title|custom_field". Note: your form fields should have the same name as the fields you wish to search, but prefixed with 'search:'. E.g. <input type="text" name="search:title">

		This plugin is best used as a tag pair wrapping {exp:channel:entries}.

		Example
		------------
		{exp:search_fields
			search:title="keyword"
			search:custom_field="keyword"
			search:cat_name="keyword"
			operator="OR"
			channel="my_channel"
			parse="inward"}
			{exp:channel:entries entry_id="{search_results}" disable="member_data|categories" dynamic="no" orderby="title" sort="asc" limit="10"}
				<a href="{page_url}">{title}</a>
			{/exp:channel:entries}
		{/exp:search_fields}

		<?php
		$buffer = ob_get_contents();
		ob_end_clean();
		return $buffer;
	}
} // END CLASS

/* End of file pi.search_fields.php */
/* Location: ./system/expressionengine/third_party/search_fields/pi.search_fields.php */
