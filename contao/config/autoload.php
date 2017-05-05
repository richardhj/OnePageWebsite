<?php

/**
 * Contao Open Source CMS
 * 
 * Copyright (C) 2005-2012 Leo Feyer
 * 
 * @package 	OnePageWebsite
 * @copyright	Tim Gatzky 2013
 * @author		Tim Gatzky <info@tim-gatzky.de>
 * @link    	http://contao.org
 * @license 	http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */

/**
 * Register the templates
 */
TemplateLoader::addFiles(array
(
	'mod_onepage'         				=> 'system/modules/OnePageWebsite/templates',
	'moo_smoothScroll' 					=> 'system/modules/OnePageWebsite/templates',
	'opw_default'     					=> 'system/modules/OnePageWebsite/templates',
	'moo_onepagewebsitenavigation' 		=> 'system/modules/OnePageWebsite/templates',
));
