<?php

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2010 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 *
 * @copyright  Tim Gatzky 2012
 * @author     Tim Gatzky <info@tim-gatzky.de>
 * @package    OnePageWebsite
 * @license    LGPL
 * @filesource
 */

/**
 * Namespaces
 */
namespace OnePageWebsite;

use Contao\ArticleModel;
use Contao\Controller;
use Contao\Database;
use Contao\FrontendTemplate;
use Contao\LayoutModel;
use Contao\Model;
use Contao\ModuleArticle;
use Contao\PageModel;


/**
 * core class OnePageWebsite
 * provids various functions
 */
class OnePageWebsite
{
    protected $pageData = [];
    protected $pages    = [];

    private $hardLimit;
    private $showLevel;


    /**
     * Get page data / layout, replace article placeholders with articles and return as array with page id key
     *
     * @param array
     *
     * @return array
     */
    protected function getPageData(array $pages): array
    {
        $pageData = $this->getModulesInPageLayouts($pages);

        if (count($pageData) < 1) {
            return [];
        }

        // insert articles in placeholders in modules array
        foreach ($pageData as $pageId => $sections) {
            foreach ($sections as $column => $itemList) {
                // replace article placeholders with articles
                foreach ($itemList as $index => $item) {
                    if ($item[0] == 'article_placeholder') {
                        $arrArticles = $this->getArticles($pageId, $column);
                        array_insert($pageData[$pageId][$column], $index, $arrArticles);

                        // delete placeholder
                        $newIndex = $index + count($arrArticles);
                        unset($pageData[$pageId][$column][$newIndex]);
                    }
                }
            }
        }

        return $pageData;
    }


    /**
     * Shortcut to getPageData: returns just the data as array
     *
     * @param integer
     *
     * @return array
     */
    protected function getSinglePageData(int $intPage): array
    {
        $arrReturn = $this->getPageData([$intPage]);
        return $arrReturn[$intPage];
    }

    /**
     * Render pages recursively and return contents as string
     *
     * @param int    $pid
     * @param int    $level
     * @param string $templateName
     *
     * @return string
     */
    public function generatePage(int $pid, int $level, string $templateName = 'opw_default'): string
    {
        global $objPage, $container;

        /** @var Database $database */
        $database = $container['database.connection'];

        $level++;
        $subPages = PageModel::findPublishedSubpagesWithoutGuestsByPid($pid);

        if (null === $subPages
            || ($this->getHardLimit() && $this->getShowLevel() > 0 && $level > $this->getShowLevel())
        ) {
            return '';
        }

        $template = new FrontendTemplate($templateName);
        $template->setData(['level' => 'level_' . $level]);
        $items = [];
        $count = 0;

        // walk subpages
        while ($subPages->next()) {
            $subSubPages = '';

            // do the same as the navigation here
            if ($subPages->subpages > 0
                && (!$this->getShowLevel()
                    || $this->getShowLevel() >= $level
                    || (!$this->getHardLimit()
                        && ($objPage->id == $subPages->id
                            || in_array(
                                $objPage->id,
                                $database->getChildRecords(
                                    $subPages->id,
                                    'tl_page'
                                )
                            ))))
            ) {
                $subSubPages = $this->generatePage($subPages->id, $level, $templateName);
            }

            $cssClass = ' page page_' . $count;
            $cssClass .= (($subSubPages != '') ? ' subpage' : '') . ($subPages->protected ? ' protected' : '')
                         . (($subPages->cssClass != '') ? ' ' . $subPages->cssClass : '');
            $cssId = 'page' . $subPages->id;

            $items[] = [
                'id'       => $subPages->id,
                'cssId'    => 'id="' . $cssId . '"',
                'class'    => trim($cssClass),
                'subpages' => $subSubPages,
                'content'  => $this->getSinglePageData($subPages->id),
                'row'      => $subPages->row()
            ];

            $count++;
        }

        if (empty($items)) {
            return '';
        }

        // add class first and last
        $last                  = count($items) - 1;
        $items[0]['class']     = trim($items[0]['class'] . ' first');
        $items[$last]['class'] = trim($items[$last]['class'] . ' last');

        $template->entries = $items;

        return $template->parse();
    }


    /**
     * Get ids of parent records and return as array
     *
     * @param string
     * @param integer
     *
     * @return array
     */
    protected function getParentRecords(string $table, int $id): array
    {
        $return = [];

        do {
            // Get the pid
            $parent = Database::getInstance()
                ->prepare("SELECT pid FROM {$table} WHERE id=?")
                ->limit(1)
                ->execute($id);

            if ($parent->numRows < 1) {
                break;
            }

            $id = $parent->pid;

            // store id
            $return[] = $id;

        } while ($id);

        return $return;
    }

    /**
     * Get layout object
     *
     * @param int $pageId
     *
     * @return LayoutModel
     * @throws \Exception
     */
    protected function getPageLayout(int $pageId): LayoutModel
    {
        global $objPage;

        // fetch layout, either selected manually or by fallback (default layout)
        /** @var LayoutModel|Model|Model\Collection $layout */
        $layout = LayoutModel::findOneBy(
            ['tl_layout.id=(SELECT layout FROM tl_page WHERE id=? AND includeLayout=1)'],
            [$pageId]
        );

        // if neither one is available search parent pages for manually selected layouts
        if (null === $layout) {
            // get parent ids
            $parentRecords = $this->getParentRecords('tl_page', $pageId);

            $tmp = [];
            foreach ($parentRecords as $id) {
                if ($id > 0 && $id != $objPage->rootId) {
                    $tmp[] = $id;
                }
            }
            $parentRecords = $tmp;
            unset($tmp);

            // move on to next page
            if (count($parentRecords)) {

                // walk parents backwards to find an inherited layout
                $parentRecords = array_reverse($parentRecords);

                // fetch parent pages
                $objParents = PageModel::findMultipleByIds($parentRecords);
                if (null !== $objParents) {
                    while ($objParents->next()) {
                        $layout = LayoutModel::findOneBy(
                            ['tl_layout.id=(SELECT layout FROM tl_page WHERE id=? AND includeLayout=1)'],
                            [$objParents->id]
                        );
                        if (null === $layout) {
                            // check next parent
                            continue;
                        }
                    }
                }
            }
        }


        // try fallback if no layout is selected or inherited to this page
        if (null === $layout) {
            // no fallback in contao 3!!!
            // fetch layout from root page, inherited in global page object
            $layout = LayoutModel::findByPk($objPage->layout);

            if (null === $layout) {
                throw new \Exception('No layout selected for the OnePageWebsite pages or the reference page');
            }
        }

        return $layout;
    }


    /**
     * Get modules included in pages and return as array with page id as key
     *
     * @param array
     *
     * @return array
     */
    protected function getModulesInPageLayouts(array $pageIds): array
    {
        global $container;

        if (!count($pageIds)) {
            return [];
        }

        // database object
        /** @var Database $database */
        $database = $container['database.connection'];

        // get Database Result object for all pages
        $pages = PageModel::findMultipleByIds($pageIds);

        if (null === $pages) {
            return [];
        }
        $return = [];

        // walk pages
        while ($pages->next()) {
            $layout = $this->getPageLayout($pages->id);

            $index = $pages->id;
            foreach (deserialize($layout->modules) as $module) {
                $id  = $module['mod'];
                $col = $module['col'];

                // make sure no modules of type one-page-website will be registered
                $objModule = $database->prepare("SELECT * FROM tl_module WHERE id=? AND type NOT IN(?)")
                    ->limit(1)
                    ->execute($id, implode(',', array_keys($GLOBALS['FE_MOD']['onepagewebsite'])));

                if ($id == 0 || $objModule->numRows < 1) {
                    // add a placeholder for articles
                    $return[$index][$col][] = ['article_placeholder', $col];
                    continue;
                }

                $html                   = Controller::getFrontendModule($id);
                $return[$index][$col][] = [
                    'id'     => $id,
                    'col'    => $col,
                    'page'   => $pages->id,
                    'layout' => $layout->id,
                    'html'   => $html,
                    'row'    => $objModule->row(),
                ];

            }
        }

        return $return;
    }


    /**
     * Get articles on pages and return as array with page id as key
     *
     * @param        array
     *
     * @param string $column
     *
     * @return array
     */
    public function getArticles(int $page, string $column = ''): array
    {
        $articles = ArticleModel::findPublishedByPidAndColumn($page, $column);

        if (null === $articles) {
            return [];
        }

        $return = [];
        while ($articles->next()) {
            /** @var ArticleModel|Model $article */
            $article = $articles->current();
            // handle teasers
            if ($article->showTeaser) {
                $article->multiMode = 1;
            }

            // mimic module article
            $tmp  = new ModuleArticle($article);
            $html = $tmp->generate(false);

            // handle empty articles
            if (!strlen($html)) {
                // generate an empty article
                $articleTemplate = new FrontendTemplate('mod_article');
                $articleTemplate->setData(['class' => 'mod_article', 'elements' => []]);
                $html = $articleTemplate->parse();
            }

            $return[] = [
                'id'   => $articles->id,
                'pid'  => $articles->pid,
                'col'  => $articles->inColumn,
                'html' => $html,
            ];
        }

        return $return;
    }


    /**
     * Shortcut: Get subpages recursiv
     *
     * @param integer
     *
     * @return array
     */
    public function getSubPages(int $pid): array
    {
        return $this->getSubPagesRecursive($pid);
    }

    /**
     * Recursivley get all subpages of a given pages
     *
     * @param int   $pid
     * @param int   $level
     * @param array $return
     *
     * @return array
     */
    protected function getSubPagesRecursive(int $pid, int $level = 1, array $return = []): array
    {
        $level++;
        $subPages = PageModel::findPublishedSubpagesWithoutGuestsByPid($pid);

        if (null === $subPages) {
            return [];
        }

        if ($this->getHardLimit() && $this->getShowLevel() > 0 && $level > $this->getShowLevel()) {
            return [];
        }

        // walk subpages
        while ($subPages->next()) {
            $this->pages[] = $subPages->id;
            $this->getSubPagesRecursive($subPages->id, $level);
        }

        return $this->pages;
    }

    /**
     * @param mixed $hardLimit
     *
     * @return self
     */
    public function setHardLimit(bool $hardLimit): self
    {
        $this->hardLimit = $hardLimit;
        return $this;
    }

    /**
     * @param mixed $showLevel
     *
     * @return self
     */
    public function setShowLevel(bool $showLevel): self
    {
        $this->showLevel = $showLevel;
        return $this;
    }

    /**
     * @return bool
     */
    public function getHardLimit(): bool
    {
        return $this->hardLimit;
    }

    /**
     * @return bool
     */
    public function getShowLevel(): bool
    {
        return $this->showLevel;
    }

}
