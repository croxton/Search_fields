#Search Fields

* Author: [Mark Croxton](http://hallmark-design.co.uk/)

## Version 2.0.3

* Requires: ExpressionEngine 2

## Description

Search channel entry titles, entry custom fields, category names, category descriptions, category custom fields and [Tagger](http://devot-ee.com/add-ons/tagger/) tags.

## Installation

1. Copy the folder search_fields to ./system/expressionengine/third_party/

## Parameters

### search:[field]=  (optional)
Field can be title, cat_name, cat_description, [custom_field_name], cat_[custom_field_name].
Tagger module tags assigned to an entry can be also be searched in the form: [tagger_custom_field_name]="tagger=[tag]"
		
Search parameter syntax is identical to the [channel search parameter](http://expressionengine.com/docs/modules/channel/parameters.html#par_search).

Note that when searching categories references to category fields should be prefixed with 'cat_'.

For example: 
search:cat_name="keyword"
search:cat_description="keyword"
search:cat_custom_field="keyword"

### channel= (optional)
 
Channel(s) name to search. Pipe-delimited list, e.g. products|pages
Default is * (searches all channels).

### operator= (optional) 

'AND' or 'OR'. Operator for joining search field WHERE conditions.
Default is 'OR'.

### delimiter= (optional) 
Delimiter for returned entry id string. 
Default is pipe |.

### placeholder= (optional)	

Single variable placeholder to replace with search results output. 
Default is search_results (use as {search_results}).

### site= (optional)	

The site id.
Default is current site id

### min_length= (optional)	

The minimum length for the search term.
Default is 3.

### dynamic_parameters= (optional) 

Allow specific search parameters to set via $_POST.
E.g. "title|custom_field". 

Note: your form fields should have the same name as the fields you wish to search, but prefixed with 'search:'. E.g.:

	<input type="text" name="search:title">

## Sample use
This plugin is best used as a tag pair wrapping {exp:channel:entries}, e.g.: 

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
	
Use in combination with [get_parameters](https://github.com/croxton/get_parameters) and an embedded template to persist POSTed search keywords for pagination, e.g.:

	{embed="search_results" search='{exp:get_parameters post="search"}'}

The search_results template could look like:

	{exp:search_fields 
		search:title="{embed:search}" 
		search:cat_name="{embed:search}" 
		search:tags="tagger={embed:search}"
		operator="OR" 
		channel="my_channel" 
		parse="inward"}
	
		<p>Search results for &lsquo;<em>{embed:search}</em>&rsquo;</p>
		{exp:channel:entries channel="my_channel" entry_id="{search_results}" disable="member_data|categories" dynamic="no" orderby="title" sort="asc" limit="10"}

			<a href="{page_url}">{title}</a>

			{paginate}
			<div class="pagination">
				<ul>
					<li class="previous">{if previous_page}<a href="{auto_path}">Prev</a>{/if}</li>
					<li class="next">{if next_page}<a href="{auto_path}">Next</a>{/if}</li>
				</ul>
				<p>Page {current_page} of {total_pages}</p>
			</div>
			{/paginate}

		{/exp:channel:entries}
	
		{if no_results}
			<p class="intro">Sorry, no matches were found for &lsquo;<em>{embed:search}</em>&rsquo;.</p>
		{/if}
	{/exp:search_fields}
	
## Caveats
This plugin can be memory intensive if you have lots of entries and search all channels. 

It is NOT a replacement for the Search module, rather it is best used for custom filtering of entries in a single channel.

