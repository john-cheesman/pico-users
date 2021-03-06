<?php
/**
 * password_hash PHP<5.5 compatibility
 * @link https://github.com/ircmaxell/password_compat
 */
require_once('password.php');
/**
 * A hierarchical users and rights system plugin for Pico.
 *
 * @author	Nicolas Liautaud
 * @link	https://github.com/nliautaud/pico-users
 * @link    http://picocms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 0.2.3
 */
class PicoUsers extends AbstractPicoPlugin
{
    private $user;
    private $users;
    private $rights;
    private $base_url;

    /**
     * Triggered after Pico has read its configuration
     *
     * @see    Pico::getConfig()
     * @param  array &$config array of config variables
     * @return void
     */
     public function onConfigLoaded(array &$config)
     {
        $this->base_url = rtrim($config['base_url'], '/') . '/';
        $this->users = @$config['users'];
        $this->rights = @$config['rights'];
        $this->user = '';
        $this->check_login();
    }
    /**
     * Triggered after Pico has evaluated the request URL
     *
     * @see    Pico::getRequestUrl()
     * @param  string &$url part of the URL describing the requested contents
     * @return void
     */
     public function onRequestUrl(&$url)
     {
        if (!$this->is_authorized($this->base_url . $url)) {
            $url = '403';
            header('HTTP/1.1 403 Forbidden');
        }
    }
    /**
     * Hide 403 and unauthorized pages.
     * 
     * Triggered after Pico has read all known pages
     * See {@link DummyPlugin::onSinglePageLoaded()} for details about the
     * structure of the page data.
     *
     * @see    Pico::getPages()
     * @see    Pico::getCurrentPage()
     * @see    Pico::getPreviousPage()
     * @see    Pico::getNextPage()
     * @param  array[]    &$pages        data of all known pages
     * @param  array|null &$currentPage  data of the page being served
     * @param  array|null &$previousPage data of the previous page
     * @param  array|null &$nextPage     data of the next page
     * @return void
     */
    public function onPagesLoaded(
        array &$pages,
        array &$currentPage = null,
        array &$previousPage = null,
        array &$nextPage = null
    ) {
        foreach ($pages as $id => $page ) {
            if ($id == '403' || !$this->is_authorized($page['url'])) {
                unset($pages[$id]);
            }
        }
    }
    /**
     * Triggered before Pico renders the page
     *
     * @see    Pico::getTwig()
     * @see    DummyPlugin::onPageRendered()
     * @param  Twig_Environment &$twig          twig template engine
     * @param  array            &$twigVariables template variables
     * @param  string           &$templateName  file name of the template
     * @return void
     */
    public function onPageRendering(Twig_Environment &$twig, array &$twigVariables, &$templateName)
    {
        $twigVariables['login_form'] = $this->html_form();
        if ($this->user) {
            $twigVariables['user'] = $this->user;
            $twigVariables['username'] = basename($this->user);
            $twigVariables['usergroup'] = dirname($this->user);
        }
    }


    // CORE ---------------

    /*
     * Check logout/login actions and session login.
     */
    function check_login()
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $fp = $this->fingerprint();

        // logout action
        if (isset($_POST['logout'])) {
            unset($_SESSION[$fp]);
            return;
        }

        // login action
        if (isset($_POST['login'])
        && isset($_POST['pass'])) {
            $users = $this->search_users($_POST['login'], $_POST['pass']);
            if (!$users) return;
            $this->log_user($users[0], $fp);
            return;
        }

        // session login (already logged)
        if (!isset($_SESSION[$fp])) return;

        $path = $_SESSION[$fp]['path'];
        $hash = $_SESSION[$fp]['hash'];
        $user = $this->get_user($path);

        if ($user['hash'] === $hash) {
            $this->log_user($user, $fp);
        }
        else unset($_SESSION[$fp]);
    }
    /**
     * Return session fingerprint hash.
     * @return string
     */
    function fingerprint()
    {
        return hash('sha256', 'pico'
                .$_SERVER['HTTP_USER_AGENT']
                .$_SERVER['REMOTE_ADDR']
                .$_SERVER['SCRIPT_NAME']
                .session_id());
    }
    /**
     * Register the given user infos.
     * @param string $user the user infos
     * @param string $fp session fingerprint hash
     */
    function log_user($user, $fp)
    {
        $this->user = $user['path'];
        $_SESSION[$fp] = $user;
    }
    /*
     * Return a simple login / logout form.
     */
    function html_form()
    {
        if (!$this->user) return '
        <form method="post" action="">
            <input type="text" name="login" />
            <input type="password" name="pass" />
            <input type="submit" value="login" />
        </form>';

        $userGroup = dirname($this->user);
        return basename($this->user) . ($userGroup != '.' ? " ($userGroup)":'') . '
        <form method="post" action="" >
            <input type="submit" name="logout" value="logout" />
        </form>';
    }

    /**
     * Return a list of users and passwords from the configuration file,
     * corresponding to the given user name.
     * @param  string $name  the user name, like "username"
     * @param  string $pass  the user pass
     * @return array  the list of results in pairs "path/group/username" => "hash"
     */
    function search_users( $name, $pass, $users = null , $path = '' )
    {
        if ($users === null) $users = $this->users;
        if ($path) $path .= '/';
        $results = array();
        foreach ($users as $username => $userdata)
        {
            if (is_array($userdata)) {
                $results = array_merge(
                    $results,
                    $this->search_users($name, $pass, $userdata, $path.$username)
                );
                continue;
            }

            if ($name !== null && $name !== $username) continue;

            if (!password_verify($pass, $userdata)) continue;

            $results[] = array(
                'path' => $path.$username,
                'hash' => $userdata);
        }
        return $results;
    }
     /**
      * Return a given user data.
      * @param  string $name  the user path, like "foo/bar"
      * @return array  the user data
      */
    function get_user( $path )
    {
        $parts = explode('/', $path);
        $curr = $this->users;
        foreach ($parts as $part) {
			if(!isset($curr[$part])) return false;
            $curr = $curr[$part];
        }
        return array(
			'path' => $path,
			'hash' => $curr);
    }

    /**
     * Return if the user is allowed to see the given page url.
     * @param  string  $url a page url
     * @return boolean
     */
    private function is_authorized($url)
    {
        if (!$this->rights) return true;
        $url = rtrim($url, '/');
        foreach ($this->rights as $auth_path => $auth_user )
        {
            // url is concerned by this rule and user is not (unauthorized)
            if ($this->is_parent_path($this->base_url . $auth_path, $url)
            && !$this->is_parent_path($auth_user, $this->user) )
            {
                return false;
            }
        }
        return true;
    }
    /**
     * Return if a path is parent of another.
     * 	some/path is parent of some/path/child
     *  some/path is not parent of some/another/path
     * @param  string  $parent the parent (shorter) path
     * @param  string  $child  the child (longer) path
     * @return boolean
     */
    private static function is_parent_path($parent, $child)
    {
        if (!$parent || !$child) return false;
        if (	$parent == $child) return true;

        if (strpos($child, $parent) === 0) {
            if (substr($parent,-1) == '/') return true;
            elseif ($child[strlen($parent)] == '/') return true;
        }
        return false;
    }
}
?>
