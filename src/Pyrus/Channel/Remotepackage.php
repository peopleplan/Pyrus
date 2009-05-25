<?php
/**
 * PEAR2_Pyrus_Channel_Remotepackage
 *
 * PHP version 5
 *
 * @category  PEAR2
 * @package   PEAR2_Pyrus
 * @author    Greg Beaver <cellog@php.net>
 * @copyright 2008 The PEAR Group
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @version   SVN: $Id$
 * @link      http://svn.pear.php.net/wsvn/PEARSVN/Pyrus/
 */

/**
 * Remote REST iteration handler
 *
 * @category  PEAR2
 * @package   PEAR2_Pyrus
 * @author    Greg Beaver <cellog@php.net>
 * @copyright 2008 The PEAR Group
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @link      http://svn.pear.php.net/wsvn/PEARSVN/Pyrus/
 */
class PEAR2_Pyrus_Channel_Remotepackage extends PEAR2_Pyrus_PackageFile_v2 implements ArrayAccess, Iterator
{
    protected $parent;
    protected $rest;
    protected $releaseList;
    protected $remotedeps;
    protected $remoteAbridgedInfo;
    protected $versionSet = false;
    protected $minimumStability;
    protected $explicitVersion;
    /**
     * Flag used to determine whether this package has been tested for upgradeability
     */
    protected $isUpgradeable = null;

    function __construct(PEAR2_Pyrus_IChannelFile $channelinfo, $releases = null)
    {
        $this->parent = $channelinfo;
        if (!isset($this->parent->protocols->rest['REST1.0'])) {
            throw new PEAR2_Pyrus_Channel_Exception('Cannot access remote packages without REST1.0 protocol');
        }
        // instruct parent::__set() to call $this->setRawVersion() when setting rawversion
        $this->rawMap['rawversion'] = array('setRawVersion');
        $this->rest = new PEAR2_Pyrus_REST;
        $this->releaseList = $releases;
        $this->minimumStability = PEAR2_Pyrus_Config::current()->preferred_state;
        $this->explicitVersion = false;
    }

    /**
     * Sets the minimum stability allowed.
     *
     * This is set by a call to a package such as "pyrus install Pname-stable"
     * or "pyrus install Pname-beta"
     *
     * The stability is only changed if it is less stable than preferred_state.
     * @param string
     */
    function setExplicitState($stability)
    {
        $states = PEAR2_Pyrus_Installer::betterStates($this->minimumStability);
        $newstates = PEAR2_Pyrus_Installer::betterStates($stability);
        if (count($newstates) > count($states)) {
            $this->minimumStability = $stability;
        }
    }

    function setExplicitVersion($version)
    {
        $this->explicitVersion = $version;
    }

    function setUpgradeable()
    {
        $this->isUpgradeable = true;
    }

    function isUpgradeable()
    {
        return $this->isUpgradeable;
    }

    function setRawVersion($var, $value)
    {
        if (isset($this->parent->protocols->rest['REST1.3'])) {
            $a = $this->remoteAbridgedInfo = $this->rest->retrieveCacheFirst(
                                                        $this->parent->protocols->rest['REST1.3']->baseurl .
                                                        'r/' . strtolower($this->name) . '/v2.' . $value['release'] . '.xml');
            $this->packageInfo['version']['api'] = $a['a'];
        } else {
            $a = $this->remoteAbridgedInfo = $this->rest->retrieveCacheFirst(
                                                        $this->parent->protocols->rest['REST1.0']->baseurl .
                                                        'r/' . strtolower($this->name) . '/v2.' . $value['release'] . '.xml');
        }
        $this->packageInfo['version'] = $value;
        $this->stability['release'] = $a['st'];
        $this->license['name'] = $a['l'];
        $this->summary = $a['s'];
        $this->description = $a['d'];
        list($this->date, $this->time) = explode(' ', $a['da']);
        $this->notes = $a['n'];
        $this->versionSet = true;
    }

    function download()
    {
        if (!$this->versionSet) {
            // this happens when doing a simple download outside of an install
            $this->rewind();
            $ok = PEAR2_Pyrus_Installer::betterStates($this->minimumStability, true);
            foreach ($this->releaseList as $versioninfo) {
                if (isset($versioninfo['m'])) {
                    // minimum PHP version required
                    if (version_compare($versioninfo['m'], $this->getPHPVersion(), '>=')) {
                        continue;
                    }
                }

                if (!in_array($versioninfo['s'], $ok) && !isset(PEAR2_Pyrus_Installer::$options['force'])) {
                    // release is not stable enough
                    continue;
                }
                $this->version['release'] = $versioninfo['v'];
                break;
            }
        }
        $url = $this->remoteAbridgedInfo['g'];
        // first try to download .phar, then .tgz, then .tar, then .zip
        $errs = new PEAR2_MultiErrors;
        try {
            return new PEAR2_Pyrus_Package_Remote($url . '.phar');
        } catch (Exception $e) {
            $errs->E_ERROR[] = $e;
        }
    
        try {
            return new PEAR2_Pyrus_Package_Remote($url . '.tgz');
        } catch (Exception $e) {
            $errs->E_ERROR[] = $e;
        }

        try {
            return new PEAR2_Pyrus_Package_Remote($url . '.tar');
        } catch (Exception $e) {
            $errs->E_ERROR[] = $e;
        }

        try {
            return new PEAR2_Pyrus_Package_Remote($url . '.zip');
        } catch (Exception $e) {
            $errs->E_ERROR[] = $e;
            throw new PEAR2_Pyrus_Package_Exception(
                'Could not download abstract package ' .
                $this->channel . '/' .
                $this->name, $errs);
        }
    }

    function offsetGet($var)
    {
        $lowerpackage = strtolower($var);
        try {
            $info = $this->rest->retrieveCacheFirst($this->parent->protocols->rest['REST1.0']->baseurl .
                                                    'p/' . $lowerpackage . '/info.xml');
        } catch (Exception $e) {
            throw new PEAR2_Pyrus_Channel_Exception('package ' . $var . ' does not exist', $e);
        }
        if (is_string($this->releaseList)) {
            $ok = PEAR2_Pyrus_Installer::betterStates($this->releaseList, true);
            if (isset($this->parent->protocols->rest['REST1.3'])) {
                $rinfo = $this->rest->retrieveCacheFirst($this->parent->protocols->rest['REST1.3']->baseurl .
                                                        'r/' . $lowerpackage . '/allreleases2.xml');
            } else {
                $rinfo = $this->rest->retrieveCacheFirst($this->parent->protocols->rest['REST1.0']->baseurl .
                                                        'r/' . $lowerpackage . '/allreleases.xml');
            }
            if (!isset($rinfo['r'][0])) {
                $rinfo['r'] = array($rinfo['r']);
            }
            $releases = array();
            foreach ($rinfo['r'] as $release) {
                if (!in_array($release['s'], $ok)) {
                    continue;
                }
                if (!isset($release['m'])) {
                    $release['m'] = '5.2.0';
                }
                $releases[] = $release;
            }
            $this->releaseList = $releases;
        }
        $pxml = clone $this;
        $pxml->channel = $info['c'];
        $pxml->name = $info['n'];
        $pxml->license = $info['l'];
        $pxml->summary = $info['s'];
        $pxml->description = $info['d'];
        return $pxml;
    }

    function offsetSet($var, $value)
    {
        throw new PEAR2_Pyrus_Channel_Exception('remote channel info is read-only');
    }

    function offsetUnset($var)
    {
        throw new PEAR2_Pyrus_Channel_Exception('remote channel info is read-only');
    }

    /**
     * This is very expensive, use sparingly if at all
     */
    function offsetExists($var)
    {
        try {
            $info = $this->rest->retrieveCacheFirst($this->parent->protocols->rest['REST1.0']->baseurl .
                                                    'p/' . strtolower($var) . '/info.xml');
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    function valid()
    {
        return current($this->releaseList);
    }

    function current()
    {
        $info = current($this->releaseList);
        if (!isset($info['m'])) {
            $info['m'] = '5.2.0'; // guess something lower than us
        }
        // setting this allows us to retrieve information specific to this
        // version
        $this->version['release'] = $info['v'];
        return array('stability' => $info['s'], 'minimumphp' => $info['m']);
    }

    function key()
    {
        $info = current($this->releaseList);
        return $info['v'];
    }

    function next()
    {
        return next($this->releaseList);
    }

    function rewind()
    {
        if ($this->releaseList) {
            return reset($this->releaseList);
        }
        if (!$this->name) {
            throw new PEAR2_Pyrus_Channel_Exception('Cannot iterate without first choosing a remote package');
        }
        if (isset($this->parent->protocols->rest['REST1.3'])) {
            $info = $this->rest->retrieveCacheFirst($this->parent->protocols->rest['REST1.3']->baseurl .
                                                    'r/' . strtolower($this->name) . '/allreleases2.xml');
        } else {
            $info = $this->rest->retrieveCacheFirst($this->parent->protocols->rest['REST1.0']->baseurl .
                                                    'r/' . strtolower($this->name) . '/allreleases.xml');
        }
        $this->releaseList = $info['r'];
        if (!isset($this->releaseList[0])) {
            $this->releaseList = array($this->releaseList);
        }
    }

    function getDependencies()
    {
        // dynamically retrieve the dependencies from the remote server when requested
        $deps = unserialize($this->rest->retrieveCacheFirst($this->parent->protocols->rest['REST1.0']->baseurl .
                                                    'r/' . strtolower($this->name) . '/deps.' .
                                                    $this->version['release'] . '.txt'));
        if ($deps) {
            $this->packageInfo['dependencies'] = $deps;
        }
        return parent::getDependencies();
    }

    function getMaintainer()
    {
        if (isset($this->parent->protocols->rest['REST1.2'])) {
            $maintainers = $this->rest->retrieveCacheFirst($this->parent->protocols->rest['REST1.2']->baseurl .
                                                    'p/' . strtolower($this->name) . '/maintainers2.xml');
            $maintainers = $maintainers['m'];
            if (!isset($maintainers[0])) {
                $maintainers = array($maintainers);
            }
            $info = array('lead' => array(), 'developer' => array(), 'contributor' => array(), 'helper' => array());
            foreach ($maintainers as $maintainer) {
                $minfo = $this->rest->retrieveCacheFirst($this->parent->protocols->rest['REST1.0']->baseurl .
                                                    'm/' . $maintainer['h'] . '/info.xml');
                $info[$maintainer['r']][] = array('name' => $minfo['n'],
                                                  'user' => $maintainer['h'],
                                                  'email' => '*hidden*',
                                                  'active' => 'yes');
            }
        } else {
            $maintainers = $this->rest->retrieveCacheFirst($this->parent->protocols->rest['REST1.0']->baseurl .
                                                    'p/' . strtolower($this->name) . '/maintainers.xml');
            $maintainers = $maintainers['m'];
            if (!isset($maintainers[0])) {
                $maintainers = array($maintainers);
            }
            $info = array('lead' => array(), 'developer' => array(), 'contributor' => array(), 'helper' => array());
            foreach ($maintainers as $maintainer) {
                $minfo = $this->rest->retrieveCacheFirst($this->parent->protocols->rest['REST1.0']->baseurl .
                                                    'm/' . $maintainer['h'] . '/info.xml');
                $info['lead'][] = array('name' => $minfo['n'],
                                        'user' => $maintainer['h'],
                                        'email' => '*hidden*',
                                        'active' => 'yes');
            }
        }
        foreach ($info as $role => $peoples) {
            foreach ($peoples as $dev) {
                $this->packageInfo[$role][] = $dev;
            }
        }
        return parent::getMaintainer();
    }

    /**
     * For unit testing purposes
     */
    function getPHPVersion()
    {
        return phpversion();
    }
    /**
     * Figure out which version is best, and use this, or error out if none work
     * @param PEAR2_Pyrus_PackageFile_v2_Dependencies_Package $compositeDep
     *        the composite of all dependencies on this package, as calculated
     *        by {@link PEAR2_Pyrus_Package_Dependency::getCompositeDependency()}
     */
    function figureOutBestVersion(PEAR2_Pyrus_PackageFile_v2_Dependencies_Package $compositeDep)
    {
        // set up release list if not done yet
        $this->rewind();
        $ok = PEAR2_Pyrus_Installer::betterStates($this->minimumStability, true);
        $v = $this->explicitVersion;
        $n = $this->channel . '/' . $this->name;
        $failIfExplicit = function() use ($v, $n) {
            if ($v && $versioninfo['v'] == $v) {
                throw new PEAR2_Pyrus_Channel_Exception($n .
                                                        ' Cannot be installed, it does not satisfy ' .
                                                        'all dependencies');
            }
        };
        foreach ($this->releaseList as $versioninfo) {
            if (isset($versioninfo['m'])) {
                // minimum PHP version required
                if (version_compare($versioninfo['m'], $this->getPHPVersion(), '>=')) {
                    $failIfExplicit();
                    continue;
                }
            }
            // now check for versions satisfying the dependency
            if (isset($compositeDep->min)) {
                if (version_compare($versioninfo['v'], $compositeDep->min, '<')) {
                    $failIfExplicit();
                    continue;
                }
            }
            if (isset($compositeDep->exclude)) {
                foreach ($compositeDep->exclude as $exclude) {
                    if ($versioninfo['v'] == $exclude) {
                        $failIfExplicit();
                        continue 2;
                    }
                }
            }
            if (isset($compositeDep->max)) {
                if (version_compare($versioninfo['v'], $compositeDep->max, '>')) {
                    $failIfExplicit();
                    continue;
                }
            }
            if (isset($compositeDep->recommended)) {
                if ($versioninfo['v'] == $compositeDep->recommended) {
                    // we're done.  That was easy.
                    $this->version['release'] = $versioninfo['v'];
                    return;
                }
                continue;
            }

            if (!in_array($versioninfo['s'], $ok) && !isset(PEAR2_Pyrus_Installer::$options['force'])) {
                // release is not stable enough
                continue;
            }
            if ($this->explicitVersion && $versioninfo['v'] != $this->explicitVersion) {
                continue;
            }
            // found one
            if ($this->versionSet && $versioninfo['v'] != $this->version['release']) {
                // inform the installer we need to reset dependencies
                $this->version['release'] = $versioninfo['v'];
                return true;
            }
            $this->version['release'] = $versioninfo['v'];
            return;
        }
        throw new PEAR2_Pyrus_Channel_Exception('Unable to locate a package release for ' .
                                                $this->channel . '/' . $this->name .
                                                ' that can satisfy all dependencies');
    }
}