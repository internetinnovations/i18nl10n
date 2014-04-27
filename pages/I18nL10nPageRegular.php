<?php

/**
 * i18nl10n Contao Module
 *
 * The i18nl10n module for Contao allows you to manage multilingual content
 * on the element level rather than with page trees.
 *
 *
 * PHP version 5
 * @copyright   Verstärker, Patric Eberle 2014
 * @copyright   Krasimir Berov 2010-2013
 * @author      Patric Eberle <line-in@derverstaerker.ch>
 * @author      Krasimir Berov
 * @package     i18nl10n
 * @license     LGPLv3 http://www.gnu.org/licenses/lgpl-3.0.html
 */


namespace Verstaerker\I18nl10n\Pages;

/**
 * Class I18nPageRegular
 *
 * @copyright  Krasimir Berov 2010-2013
 * @author     Krasimir Berov
 * @package    Controller
 */
class I18nL10nPageRegular extends \PageRegular
{
    //override_function
    function generate($objPage, $blnCheckRequest = false)
    {
        $this->fixupCurrentLanguage();

        if ($GLOBALS['TL_LANGUAGE'] == $GLOBALS['TL_CONFIG']['i18nl10n_default_language'])
        {
            if ($objPage->i18nl10n_hide != '')
            {
                header('HTTP/1.1 404 Not Found');
                $message = 'Page "'
                    . $objPage->alias
                    . '" is hidden for default language "'
                    . $objPage->language
                    . '". See "Publish settings/Hide default language" for Page ID '
                    . $objPage->id;
                $this->log($message, __METHOD__, TL_ERROR);
                die($message);
            }
            return parent::generate($objPage);
        }

        //get language specific page properties
        $fields = 'title,language,pageTitle,description,cssClass,dateFormat,timeFormat,datimFormat,published,start,stop';

        $sql = "
            SELECT
              $fields
            FROM
              tl_page_i18nl10n
            WHERE
              pid = ?
              AND language = ?
        ";

        if(!BE_USER_LOGGED_IN)
        {
            $time = time();
            $sql .= "
                AND (start = '' OR start < $time)
                AND (stop = '' OR stop > $time)
                AND published = 1
            ";
        }

        $l10n = $this->Database->prepare($sql)
            ->limit(1)
            ->execute($objPage->id, $GLOBALS['TL_LANGUAGE']);

        // if translated page, replace given fields in page object
        if ($l10n->numRows)
        {
            $objPage->defaultPageTitle = $objPage->pageTitle;
            $objPage->defaultTitle = $objPage->title;

            foreach (explode(',', $fields) as $field)
            {
                if ($l10n->$field)
                {
                    $objPage->$field = $l10n->$field;
                }
            }
        }

        return parent::generate($objPage);
    }


    /**
     * Fix up current language depending on momentary user preference.
     * Strangely $GLOBALS['TL_LANGUAGE'] is switched to the current user language if user is just
     * authentitcating and has the language property set.
     * See system/libraries/User.php:202
     * We override this behavior and let the user temporarily use the selected by him language.
     * One workaround would be to not let the members have a language property.
     * Then this method will not be needed any more.
     */
    private function fixupCurrentLanguage()
    {
        // if language is added to url, get it from there
        if ($GLOBALS['TL_CONFIG']['i18nl10n_addLanguageToUrl'])
        {
            $this->import('Environment');
            $environment = $this->Environment;
            $scriptName = preg_quote($environment->scriptName);
            $strUrl = $environment->requestUri;

            // TODO: Compare against settings??

            // if scriptName is part of url
            if ($GLOBALS['TL_CONFIG']['rewriteURL'])
            {
                $regex = "@^/([A-z]{2}(?=/)){1}(/.*)@";
            }
            else
            {
                $regex = "@^$scriptName/([A-z]{2}(?=/)){1}(/.*)@";
            }

            $urlLanguage = preg_replace(
                $regex, '$1', $strUrl
            );

            $_SESSION['TL_LANGUAGE'] = $GLOBALS['TL_LANGUAGE'] = $urlLanguage;

            return;
        }

        $selectedLanguage = \Input::post('language') ?: \Input::get('language');

        if ($selectedLanguage
            && in_array($selectedLanguage,
                deserialize($GLOBALS['TL_CONFIG']['i18nl10n_languages'])))
        {
            $_SESSION['TL_LANGUAGE'] = $GLOBALS['TL_LANGUAGE'] = $selectedLanguage;
        }
        elseif (isset($_SESSION['TL_LANGUAGE']))
        {
            $GLOBALS['TL_LANGUAGE'] = $_SESSION['TL_LANGUAGE'];
        }
    }

    /**
     * Generate an article and return it as string
     * The only thing I changed here is:
     * $objArticle = new I18nL10nModuleArticle($objArticle, $strColumn);
     *
     * TODO: Ask leo to allow something similar to
     * $GLOBALS['FE_MOD']['navigationMenu']['navigation'] for articles
     * (e.g. $GLOBALS['FE_MOD']['content]['article']='ModuleArticle'; )
     *
     * @param mixed   $varId          The article ID or a Model object
     * @param boolean $blnMultiMode   If true, only teasers will be shown
     * @param boolean $blnIsInsertTag If true, there will be no page relation
     * @param string  $strColumn      The name of the column
     *
     * @return string|boolean The article HTML markup or false
     */
    /*protected function getArticle($varId, $blnMultiMode = false, $blnIsInsertTag = false, $strColumn = 'main')
    {
        if (!$varId) {
            return '';
        }

        global $objPage;
        $this->import('Database');

        // Get article
        $objRow = $this->Database->prepare("SELECT *, author AS authorId, (SELECT name FROM tl_user WHERE id=author) AS author FROM tl_article WHERE (id=? OR alias=?)" . (!$blnIsInsertTag ? " AND pid=?" : ""))
            ->limit(1)
            ->execute((is_numeric($varId) ? $varId : 0), $varId, $objPage->id);

        if ($objRow->numRows < 1) {
            return false;
        }


        if (!file_exists(TL_ROOT . '/system/modules/frontend/ModuleArticle.php')) {
            $this->log('Class ModuleArticle does not exist', 'Controller getArticle()', TL_ERROR);
            return '';
        }

        // Print article as PDF
        if ($this->Input->get('pdf') == $objRow->id) {
            $objArticle = new \ModuleArticle($objArticle);

            // Backwards compatibility
            if ($objRow->printable == 1) {
                $objArticle->generatePdf();
            } // New structure
            elseif ($objRow->printable != '') {
                $options = deserialize($objRow->printable);

                if (is_array($options) && in_array('pdf', $options)) {
                    $objArticle->generatePdf();
                }
            }
        }

        $objRow->headline = $objRow->title;
        $objRow->multiMode = $blnMultiMode;

        // HOOK: add custom logic
        if (isset($GLOBALS['TL_HOOKS']['getArticle']) && is_array($GLOBALS['TL_HOOKS']['getArticle'])) {
            foreach ($GLOBALS['TL_HOOKS']['getArticle'] as $callback) {
                $this->import($callback[0]);
                $this->$callback[0]->$callback[1]($objRow);
            }
        }

        $objArticle = new I18nL10nModuleArticle($objRow, $strColumn);
        return $objArticle->generate($blnIsInsertTag);
    }*/

    /**
     * Generate content in the current language from articles
     * using insert tags.
     * A HOOK called in Controller::replaceInsertTags()!!
     * @param string $insert_tag The insert tag with the alias or id
     * @return string|boolean
     */
    public function insertI18nL10nArticle($insert_tag)
    {
        if (strpos($insert_tag, 'insert_i18nl10n_article') === false)
            return false;

        $tag = explode('::', $insert_tag);
        if (($strOutput = $this->getArticle($tag[1], false, true)) !== false) {
            return $this->replaceInsertTags(ltrim($strOutput));
        } else {
            return '<p class="error">'
            . sprintf($GLOBALS['TL_LANG']['MSC']['invalidPage'], $tag[1])
            . '</p>';
        }
    }
}