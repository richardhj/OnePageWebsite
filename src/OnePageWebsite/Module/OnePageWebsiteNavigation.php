<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (C) 2005-2013 Leo Feyer
 *
 * @copyright      Tim Gatzky 2013
 * @author         Tim Gatzky <info@tim-gatzky.de>
 * @package        OnePageWebsite
 * @link           http://contao.org
 * @license        http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */

/**
 * Namespaces
 */
namespace OnePageWebsite\Module;

use Contao\Database;
use Contao\FrontendTemplate;
use Contao\FrontendUser;
use Contao\Model;
use Contao\ModuleModel;
use Contao\ModuleNavigation;
use Contao\ModuleSitemap;
use Contao\PageModel;

/**
 * Classes
 */
class OnePageWebsiteNavigation extends ModuleNavigation
{
    /**
     * Display a wildcard in the back end
     *
     * @return string
     */
    public function generate()
    {
        if (TL_MODE == 'BE') {
            $this->Template           = new \BackendTemplate('be_wildcard');
            $this->Template->wildcard =
                '### ONE-PAGE-WEBSITE :: NAVIGATION ###' . "<br>" . $GLOBALS['TL_LANG']['FMD'][$this->type][0];
            $this->Template->title    = $this->headline;

            return $this->Template->parse();
        }

        return parent::generate();
    }

    /**
     * Generate the module
     */
    protected function compile()
    {
        global $objPage;

        if (!$this->rootPage) {
            return '';
        }

        // I know its not nice but yes I use the rootPage field for the module selection
        $this->rootModule = $this->rootPage;

        // fetch reference module
        /** @var ModuleModel|Model $module */
        $module = ModuleModel::findOneBy('id', $this->rootModule);

        if (null === $module) {
            return '';
        }

        // set rootPage from module
        $this->rootPage = $module->rootPage;

        // set new jumpTo page
        if (!$this->jumpTo) {
            $this->jumpTo = $objPage->id;
        }

        //(issue #2)
        $this->Template->skipId         = 'skipNavigation' . $this->id;
        $this->Template->skipNavigation = specialchars($GLOBALS['TL_LANG']['MSC']['skipNavigation']);

        $this->Template->items = $this->renderNavigation($this->rootPage);
        return '';
    }


    /**
     * Recursively compile the navigation menu and return it as HTML string
     *
     * @param int  $pid
     * @param int  $level
     * @param string $host
     * @param string $language
     *
     * @return string Taken and modified from Modules.php
     * Taken and modified from Modules.php
     *
     */
    protected function renderNavigation($pid, $level = 1, $host = null, $language = null)
    {
        global $objPage, $container;

        // Get all active subpages
        $subPages = \PageModel::findPublishedSubpagesWithoutGuestsByPid($pid, $this->showHidden, $this instanceof \ModuleSitemap);

        if (null === $subPages)
        {
            return '';
        }

        $items  = [];
        $groups = [];

        // Get all groups of the current front end user
        if (FE_USER_LOGGED_IN) {
            /** @var FrontendUser $user */
            $user   = $container['user'];
            $groups = $user->groups;
        }

        // Layout template fallback
        if ('' === $this->navigationTpl) {
            $this->navigationTpl = 'nav_default';
        }

        $template = new FrontendTemplate($this->navigationTpl);

        $template->pid = $pid;
        $template->type  = get_class($this);
        $template->cssID = $this->cssID; // see #4897
        $template->level = 'level_' . $level++;


        // jumpTo page
        /** @var PageModel|Model $jumpTo */
        $jumpTo = PageModel::findOneBy('id', $this->jumpTo);

        // Browse subpages
        while ($subPages->next()) {
            // Skip hidden sitemap pages
            if ($this instanceof \ModuleSitemap && $subPages->sitemap == 'map_never') {
                continue;
            }

            $subitems = '';
            $_groups  = deserialize($subPages->groups);

            // Do not show protected pages unless a back end or front end user is logged in
            if (!$subPages->protected || BE_USER_LOGGED_IN
                || (is_array($_groups)
                    && count(
                        array_intersect($_groups, $groups)
                    ))
                || $this->showProtected
                || ($this instanceof ModuleSitemap && $subPages->sitemap == 'map_always')
            ) {
                // Check whether there will be subpages
                if ($subPages->subpages > 0
                    && (!$this->showLevel || $this->showLevel >= $level
                        || (!$this->hardLimit
                            && ($objPage->id == $subPages->id
                                || in_array(
                                    $objPage->id,
                                    Database::getInstance()->getChildRecords(
                                        $subPages->id,
                                        'tl_page'
                                    )
                                ))))
                ) {
                    $subitems = $this->renderNavigation($subPages->id, $level);
                }

                // href
                if ($jumpTo->id != $objPage->id || $objPage->id != $objPage->rootId) {
                    $href = $this->generateFrontendUrl($jumpTo->row()) . '#page' . $subPages->id;
                } else {
                    $href = '#page' . $subPages->id;
                }

                $strClass = (($subitems != '') ? 'submenu' : '') . ($subPages->protected ? ' protected' : '')
                            . (($subPages->cssClass != '') ? ' ' . $subPages->cssClass : '') . (in_array(
                        $subPages->id,
                        $objPage->trail
                    ) ? ' trail' : '');

                // Mark pages on the same level (see #2419)
                if ($subPages->pid == $objPage->pid) {
                    $strClass .= ' sibling';
                }

                $row = $subPages->row();

                $row['isActive']    = false;
                $row['subitems']    = $subitems;
                $row['class']       = trim($strClass);
                $row['title']       = specialchars($subPages->title, true);
                $row['pageTitle']   = specialchars($subPages->pageTitle, true);
                $row['link']        = $subPages->title;
                $row['href']        = $href;
                $row['nofollow']    = (strncmp($subPages->robots, 'noindex', 7) === 0);
                $row['target']      = '';
                $row['description'] = str_replace(["\n", "\r"], array(' ', ''), $subPages->description);

                // Override the link target
                if ($subPages->type == 'redirect' && $subPages->target) {
                    $row['target'] = ($objPage->outputFormat
                                      == 'xhtml') ? ' onclick="return !window.open(this.href)"' : ' target="_blank"';
                }

                $items[] = $row;

            }
        }

        // Add classes first and last
        if (!empty($items)) {
            $last = count($items) - 1;

            $items[0]['class']     = trim($items[0]['class'] . ' first');
            $items[$last]['class'] = trim($items[$last]['class'] . ' last');
        }

        $template->items = $items;
        return !empty($items) ? $template->parse() : '';
    }

}
