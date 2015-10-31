<?php

use phpList\plugin\ContentAreas\DAO;
use phpList\plugin\ContentAreas\MessageModel;
use phpList\plugin\ContentAreas\TemplateModel;
use phpList\plugin\Common\DB;
use phpList\plugin\Common\PageLink;
use phpList\plugin\Common\PageURL;

class ContentAreas extends phplistPlugin
{
    const VERSION_FILE = 'version.txt';
    const VIEW_PAGE = 'view';
    const PLUGIN = 'ContentAreas';

    private $linkText;
    private $dao;

    /*
     *  Inherited variables
     */
    public $name = 'Content Areas';
    public $enabled = true;
    public $authors = 'Duncan Cameron';
    public $description = 'Provides multiple content areas for campaigns';
    public $settings = array(
        'contentareas_inline_css' => array(
            'value' => true,
            'description' => 'Automatically inline css',
            'type' => 'boolean',
            'allowempty' => 1,
            'category' => 'Content Areas',
        ),
        'contentareas_iframe_height' => array(
            'value' => 800,
            'min' => 500,
            'max' => 1000,
            'description' => 'Height in px of the iframe',
            'type' => 'integer',
            'allowempty' => false,
            'category' => 'Content Areas',
        ),
        'contentareas_iframe_width' => array(
            'value' => 660,
            'min' => 500,
            'max' => 800,
            'description' => 'Width in px of the iframe',
            'type' => 'integer',
            'allowempty' => false,
            'category' => 'Content Areas',
        ),
    );
    public $publicPages = array(self::VIEW_PAGE);

    /*
     * Private functions
     */
    private function viewUrl($messageid, $uid)
    {
        global $public_scheme, $pageroot;

        $params = array('p' => self::VIEW_PAGE, 'pi' => self::PLUGIN, 'm' => $messageid);

        if ($uid) {
            $params['uid'] = $uid;
        }

        $url = sprintf('%s://%s%s/', $public_scheme, getConfig('website'), $pageroot);

        return $url . '?' . http_build_query($params, '', '&');
    }

    private function link($linkText, $url)
    {
        return sprintf('<a href="%s">%s</a>', htmlspecialchars($url), htmlspecialchars($linkText));
    }

    /*
     * Public functions
     */
    public function __construct()
    {
        $this->coderoot = dirname(__FILE__) . '/' . __CLASS__ . '/';
        $this->version = (is_file($f = $this->coderoot . self::VERSION_FILE))
            ? file_get_contents($f)
            : '';
        parent::__construct();
        $this->linkText = getConfig('viewbrowser_link');
    }

    public function dependencyCheck()
    {
        global $plugins;

        return array(
            'XSL extension installed' => extension_loaded('xsl'),
            'Common plugin v3.2.0 or later installed' => phpListPlugin::isEnabled('CommonPlugin')
                && preg_match('/\d+\.\d+\.\d+/', $plugins['CommonPlugin']->version, $matches)
                && version_compare($matches[0], '3.2.0') >= 0,
            'View in Browser plugin v2.2.0 or later installed' => (phpListPlugin::isEnabled('ViewBrowserPlugin')
                    && version_compare($plugins['ViewBrowserPlugin']->version, '2.2.0') >= 0
                )
                || !phpListPlugin::isEnabled('ViewBrowserPlugin'),
            'PHP version 5.4.0 or greater' => version_compare(PHP_VERSION, '5.4') > 0,
        );
    }

    public function adminmenu()
    {
        return array();
    }

    /**
     * Use this hook to create the dao.
     */
    public function sendFormats()
    {
        global $plugins;

        require_once $plugins['CommonPlugin']->coderoot . 'Autoloader.php';
        $this->dao = new DAO(new DB());

        return;
    }

    /**
     * Create the content for the Send Campaign tab.
     * 
     * @param int   $messageId the message id
     * @param array $data      the message data
     * 
     * @return string
     */
    public function sendMessageTab($messageId = 0, $data = array())
    {
        $level = error_reporting(E_ALL | E_STRICT);
        set_error_handler('phpList\plugin\Common\Exception::errorHandler', E_ALL | E_STRICT);

        if ($data['template'] == 0) {
            return '';
        }
        $templateBody = $this->dao->templateBody($data['template']);

        if (!($templateBody && TemplateModel::isTemplateBody($templateBody))) {
            return '';
        }
        $preview = new PageLink(
            new PageURL('message_page', array('pi' => __CLASS__, 'action' => 'preview', 'id' => $messageId)),
            'Preview',
            array('class' => 'button', 'target' => 'preview')
        );
        error_reporting($level);
        $iframe = $this->iframe('display', $messageId);

        return <<<END
$preview
$iframe
END;
    }

    public function sendMessageTabTitle($messageid = 0)
    {
        return s('Edit Areas');
    }

    /**
     * Create the content, an iframe, for the view message page.
     * 
     * @param int   $messageId the message id
     * @param array $data      the message data
     * 
     * @return array|false the caption and content or false if the message
     *                     does not use content areas
     */
    public function viewMessage($messageId, array $data)
    {
        if ($data['template'] == 0) {
            return false;
        }
        $templateBody = $this->dao->templateBody($data['template']);

        if (!($templateBody && TemplateModel::isTemplateBody($templateBody))) {
            return false;
        }
        $iframe = $this->iframe('preview', $messageId);

        return array('Message', $iframe);
    }

    /**
     * Replace placeholders.
     * 
     * @param int    $messageid   the message id
     * @param string $content     the message content
     * @param string $destination the destination email address
     * @param array  $userdata    the user data
     * 
     * @return string
     */
    public function parseOutgoingHTMLMessage($messageid, $content, $destination, $userdata = null)
    {
        $url = $this->viewUrl($messageid, $userdata['uniqid']);

        return str_ireplace(
            array('[CAVIEWBROWSER]', '[CAVIEWBROWSERURL]'),
            array($this->link($this->linkText, $url), htmlspecialchars($url)),
            $content
        );
    }

    /**
     * Replace placeholders.
     * 
     * @param int    $messageid   the message id
     * @param string $content     the message content
     * @param string $destination the destination email address
     * @param array  $userdata    the user data
     * 
     * @return string
     */
    public function parseOutgoingTextMessage($messageid, $content, $destination, $userdata = null)
    {
        $url = $this->viewUrl($messageid, $userdata['uniqid']);

        return str_ireplace(
            array('[CAVIEWBROWSER]', '[CAVIEWBROWSERURL]'),
            array("$this->linkText $url", $url),
            $content
        );
    }

    /**
     * Merge the template with content areas. This is done just once for the campaign.
     * 
     * @param int   $messageId the message id
     * @param array &$message  the message data
     */
    public function processPrecachedCampaign($messageId, array &$message)
    {
        if ($message['template']) {
            $tm = new TemplateModel($message['template']);

            if ($tm->isTemplate()) {
                $mm = new MessageModel($messageId, $this->dao);
                $merged = $tm->merge($mm->messageAreas());
                $message['content'] = str_ireplace('[CONTENT]', $message['content'], $merged);
                $message['template'] = '';
                $message['htmlformatted'] = true;
            }
        }
    }

    /**
     * Create an iframe element that will display the campaign.
     * 
     * @param string $action    value for the action query parameter
     * @param int    $messageId the message id
     * 
     * @return string
     */
    public function iframe($action, $messageId)
    {
        $url = htmlspecialchars(new PageURL(
            'message_page', array('pi' => __CLASS__, 'action' => $action, 'id' => $messageId)
        ));
        $width = getConfig('contentareas_iframe_width');
        $height = getConfig('contentareas_iframe_height');

        return <<<END
<iframe src="$url" width="$width" height="$height">
</iframe>
END;
    }

    /**
     * Setter to allow injecting a dao.
     * 
     * @param phpList\plugin\ContentAreas\DAO $dao
     */
    public function setDao(DAO $dao)
    {
        $this->dao = $dao;
    }
}
