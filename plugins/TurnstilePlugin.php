<?php

/**
 * TurnstilePlugin for phplist.
 *
 * This file is a part of TurnstilePlugin.
 *
 * @author    Duncan Cameron
 * @copyright 2025 Duncan Cameron
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License, Version 3
 *
 * @see       https://developers.cloudflare.com/turnstile/
 */
use phpList\plugin\Common\Logger;
use phpList\plugin\Common\StringStream;

/**
 * This class registers the plugin with phplist and hooks into the display and validation
 * of subscribe pages.
 */
class TurnstilePlugin extends phplistPlugin
{
    /** @var string the name of the version file */
    const VERSION_FILE = 'version.txt';

    /** @var string the site key */
    private $siteKey;

    /** @var string the secret key */
    private $secretKey;

    /** @var bool whether the site and secret keys have been entered */
    private $keysEntered;

    /*
     *  Inherited from phplistPlugin
     */
    public $name = 'Turnstile Plugin';
    public $description = 'Uses Cloudflare Turnstile to verify subscribe forms';
    public $documentationUrl = 'https://resources.phplist.com/plugin/turnstile';
    public $authors = 'Duncan Cameron';
    public $coderoot;

    /**
     * Class constructor.
     * Initialises some dynamic variables.
     */
    public function __construct()
    {
        $this->coderoot = __DIR__ . '/' . __CLASS__ . '/';

        parent::__construct();

        $this->version = (is_file($f = $this->coderoot . self::VERSION_FILE))
            ? file_get_contents($f)
            : '';
    }

    /**
     * Provide the dependencies for enabling this plugin.
     *
     * @return array
     */
    public function dependencyCheck()
    {
        global $plugins;

        return array(
            'Common Plugin v3.28.0 or later installed' => (
                phpListPlugin::isEnabled('CommonPlugin')
                && version_compare($plugins['CommonPlugin']->version, '3.28.0') >= 0
            ),
            'phpList version 3.6.11 or later' => version_compare(VERSION, '3.6.11') >= 0,
        );
    }

    /**
     * Cache the plugin's config settings.
     * Turnstile will be used only when both the site key and secrety key have
     * been entered.
     */
    public function activate()
    {
        $this->settings = array(
            'turnstile_sitekey' => array(
                'description' => s('Turnstile site key'),
                'type' => 'text',
                'value' => '',
                'allowempty' => false,
                'category' => 'Turnstile',
            ),
            'turnstile_secretkey' => array(
                'description' => s('Turnstile secret key'),
                'type' => 'text',
                'value' => '',
                'allowempty' => false,
                'category' => 'Turnstile',
            ),
        );

        parent::activate();

        $this->siteKey = getConfig('turnstile_sitekey');
        $this->secretKey = getConfig('turnstile_secretkey');
        $this->keysEntered = $this->siteKey !== '' && $this->secretKey !== '';
    }

    /**
     * Provide the html to be included in a subscription page.
     *
     * @param array $pageData subscribe page fields
     * @param int   $userId   user id
     *
     * @return string
     */
    public function displaySubscriptionChoice($pageData, $userID = 0)
    {
        if (!$this->keysEntered) {
            return '';
        }
        $format = <<<'END'
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" defer></script>
<div class="cf-turnstile" data-sitekey="%s"></div>
END;

        return sprintf($format, $this->siteKey);
    }

    /**
     * Provide additional validation when a subscribe page has been submitted.
     *
     * @param array $pageData subscribe page fields
     *
     * @return string an error message to be displayed or an empty string
     *                when validation is successful
     */
    public function validateSubscriptionPage($pageData)
    {
        if (empty($_POST) || $_GET['p'] != 'subscribe') {
            return '';
        }

        if (empty($_POST['cf-turnstile-response'])) {
            return 'Turnstile response missing';
        }
        $data = [
            'secret' => $this->secretKey,
            'response' => $_POST['cf-turnstile-response'],
        ];
        $request = new HTTP_Request2('https://challenges.cloudflare.com/turnstile/v0/siteverify', HTTP_Request2::METHOD_POST);
        $request->addPostParameter($data);
        $logOutput = '';
        $request->attach(new HTTP_Request2_Observer_Log(StringStream::fopen($logOutput, 'w')));
        $response = $request->send();
        Logger::instance()->debug("\n" . $logOutput);
        $responseData = json_decode($response->getBody());

        if ($responseData->success) {
            return '';
        }

        return isset($responseData->{'error-codes'})
            ? implode(', ', $responseData->{'error-codes'})
            : 'unspecified error';
    }
}
