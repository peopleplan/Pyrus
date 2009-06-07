<?php
/**
 * PEAR2_Pyrus_Package_Remote
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
 * Class representing a remote package
 *
 * @category  PEAR2
 * @package   PEAR2_Pyrus
 * @author    Greg Beaver <cellog@php.net>
 * @copyright 2008 The PEAR Group
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @link      http://svn.pear.php.net/wsvn/PEARSVN/Pyrus/
 */
class PEAR2_Pyrus_Package_Remote extends PEAR2_Pyrus_Package
{
    private $_info;
    protected $parsedname;
    protected $explicitState = false;
    protected $explicitVersion;
    protected $explicitGroup;
    protected $type;
    protected $isUpgradeable = null;
    /**
     * @param string $package path to package file
     */
    function __construct($package, PEAR2_Pyrus_Package $parent = null)
    {
        $this->_info = $package;
        if (!is_array($package) &&
              (preg_match('#^(http[s]?|ftp[s]?)://#', $package))) {
            $this->internal = $this->fromUrl($package);
        } else {
            $this->internal = $this->fromString($package);
        }
        $this->from = $parent;
    }

    function isStatic()
    {
        if ($this->type == 'url') {
            return true;
        }
        return $this->explicitVersion;
    }

    function getExplicitState()
    {
        return $this->explicitState;
    }

    function setUpgradeable()
    {
        $this->isUpgradeable = true;
    }

    function isUpgradeable()
    {
        if ($this->isUpgradeable === null) {
            // we are not a dependency, so figure out a version that could work
            if (!isset(PEAR2_Pyrus::$options['upgrade'])) {
                // we don't attempt to upgrade a dep unless we're upgrading
                return;
            }
            $reg = PEAR2_Pyrus_Config::current()->registry;
            $version = $reg->info($this->name, $this->channel, 'version');
            $stability = $reg->info($this->name, $this->channel, 'state');
            if ($this->explicitState) {
                $stability = $this->explicitState;
            } else {
                $installedstability = PEAR2_Pyrus_Installer::betterStates($stability);
                $preferred = PEAR2_Pyrus_Installer::betterStates($pref =PEAR2_Pyrus_Config::current()->preferred_state);
                if (count($preferred) < count($installedstability)) {
                    $stability = $pref;
                }
            }
            // see if there are new versions in our stability or better
            $remote = new PEAR2_Pyrus_Channel_Remotepackage(PEAR2_Pyrus_Config::current()
                                                            ->channelregistry[$this->channel], $stability);
            $found = false;
            foreach ($remote[$this->name] as $remoteversion => $rinfo) {
                if (version_compare($remoteversion, $version, '<=')) {
                    continue;
                }
                if (version_compare($rinfo['minimumphp'], phpversion(), '>')) {
                    continue;
                }
                // found one, so upgrade is possible if dependencies pass
                $found = true;
                break;
            }
            // the installed package version satisfies this dependency, don't do anything
            if (!$found) {
                $this->isUpgradeable = false;
            } else {
                $this->isUpgradeable = true;
            }
        }
        return $this->isUpgradeable;
    }

    /**
     * Convert this remote packagefile into a local .tar, .tgz or .phar
     *
     * @return PEAR2_Pyrus_Package_Base
     */
    function download()
    {
        if ($this->type === 'url') {
            return $this->internal;
        }

        $internal = $this->internal->download();
        if ($internal->name != $this->name) {
            throw new PEAR2_Pyrus_Package_Exception('Invalid package downloaded, package name changed from ' .
                                                    $this->name . ' to ' . $internal->name);
        }
        if ($internal->channel != $this->channel) {
            throw new PEAR2_Pyrus_Package_Exception('SECURITY ERROR: package is claiming to be from ' .
                                                    'channel ' . $internal->channel . ', but we are ' .
                                                    'channel ' . $this->name);
        }
        $internal->setFrom($this->internal);
        $this->internal = $internal;
        return $this->internal;
    }

    function copyTo($where)
    {
        $old = PEAR2_Pyrus_Config::current()->download_dir;
        PEAR2_Pyrus_Config::current()->download_dir = $where;
        $this->download();
        PEAR2_Pyrus_Config::current()->download_dir = $old;
        return;
    }

    protected function fromUrl($param, $saveparam = '')
    {
        $this->type = 'url';
        $dir = PEAR2_Pyrus_Config::current()->download_dir;
        try {
            $response = PEAR2_Pyrus::downloadWithProgress($param);
            $name = 'unknown.tgz';
            if ($response->code != '200') {
                throw new PEAR2_Pyrus_Package_Exception('Download failed, received ' . $response->code);
            }

            if (isset($response->headers['content-disposition'])) {
                if (preg_match('/filename="(.+)"/', $response->headers['content-disposition'], $match)) {
                    $name = $match[1];
                }
            }

            if (!@file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $response->body);

            // whew, download worked!
            $a = new PEAR2_Pyrus_Package($dir . DIRECTORY_SEPARATOR . $name);
            return $a->getInternalPackage();
        } catch (PEAR2_Pyrus_HTTPException $e) {
            throw $e; // pass it along
        } catch (Exception $e) {
            if (!empty($saveparam)) {
                $saveparam = ", cannot download \"$saveparam\"";
            }
            throw new PEAR2_Pyrus_Package_Exception('Could not download from "' . $param .
                '"' . $saveparam, $e);
        }
    }

    /**
     *
     * @param string|array pass in an array of format
     *                     array(
     *                      'package' => 'pname',
     *                     ['channel' => 'channame',]
     *                     ['version' => 'version',]
     *                     ['state' => 'state',])
     *                     or a string of format [channame/]pname[-version|-state]
     */
    protected function fromString($param)
    {
        try {
            $pname = PEAR2_Pyrus_Config::parsePackageName($param, true);
        } catch (PEAR2_Pyrus_ChannelRegistry_ParseException $e) {
            if ($e->why !== 'channel') {
                throw new PEAR2_Pyrus_Package_Exception(
                    'invalid package name/package file "' . $param . '"', $e);
            }

            if (PEAR2_Pyrus_Config::current()->auto_discover) {
                try {
                    try {
                        $chan = new PEAR2_Pyrus_Channel(
                                    new PEAR2_Pyrus_ChannelFile('https://' . $e->params['channel'] . '/channel.xml',
                                                                false, true));
                    } catch (\Exception $e) {
                        $chan = new PEAR2_Pyrus_Channel(
                                    new PEAR2_Pyrus_ChannelFile('http://' . $e->params['channel'] . '/channel.xml',
                                                                false, true));
                    }
                } catch (\Exception $e) {
                    throw new PEAR2_Pyrus_Package_Exception(
                        'Cannot auto-discover channel ' . $e->params['channel'], $e);
                }
                PEAR2_Pyrus_Config::current()->channelregistry[] = $chan;
                try {
                    PEAR2_Pyrus_Config::parsePackageName($param,
                            PEAR2_Pyrus_Config::current()->default_channel);
                } catch (\Exception $e) {
                    throw new PEAR2_Pyrus_Package_Exception(
                        'invalid package name/package file "' . $param . '"', $e);
                }
            } else {
                PEAR2_Pyrus_Log::log(0, 'Channel "' . $param['channel'] .
                    '" is not initialized, use ' .
                    '"pyrus channel-discover ' . $param['channel'] . '" to initialize' .
                    'or pyrus set auto_discover 1');
            }
        }

        $this->parsedname    = $pname;
        $this->explicitVersion = isset($pname['version']) ? $pname['version'] : false;
        $this->explicitState = isset($pname['state']) ? $pname['state'] : false;
        $this->explicitGroup = isset($pname['group']) ? true            : false;

        $reg = PEAR2_Pyrus_Config::current()->registry;
        $version = $reg->info($pname['package'], $pname['channel'], 'version');
        $stability = $reg->info($pname['package'], $pname['channel'], 'state');

        if (!isset(PEAR2_Pyrus::$options['force']) &&
              !isset(PEAR2_Pyrus::$options['downloadonly']) &&
              $version && $this->explicitVersion &&
              !isset($pname['group'])) {
            if (version_compare($version, $pname['version'], '>=')) {
                throw new PEAR2_Pyrus_Package_InstalledException(
                    PEAR2_Pyrus_Config::parsedPackageNameToString($parr, true) .
                    ' is already installed and is newer than detected ' .
                    'release version ' . $pname['version']);
            }
        }
        if (!$this->explicitVersion && $stability) {
            // if installed, use stability of the installed package,
            // but only if it is less restrictive than preferred_state.
            // This allows automatic upgrade to a newer beta for 1 package
            // even if preferred_state is stable, for instance.
            $states = PEAR2_Pyrus_Installer::betterStates(PEAR2_Pyrus_Config::current()->preferred_state);
            $newstates = PEAR2_Pyrus_Installer::betterStates($stability);
            if (count($newstates) > count($states)) {
                $this->explicitState = $stability;
            }
        }

        $this->type = 'abstract';
        $ret = $this->getRemotePackage($pname);
        if ($this->explicitVersion) {
            $ret->setExplicitVersion($this->explicitVersion);
            $ret->version['release'] = $this->explicitVersion;
        }
        if ($this->explicitState) {
            $ret->setExplicitState($this->explicitState);
        }
        return $ret;
    }

    /**
     * @param array output of {@link parsePackageName()}
     * @return PEAR2_Pyrus_Channel_RemotePackage
     * @access private
     */
    function getRemotePackage($parr)
    {
        // getDownloadURL returns an array.  On error, it only contains information
        // on the latest release as array(version, info).  On success it contains
        // array(version, info, download url string)
        $state = isset($parr['state']) ? $parr['state'] :  PEAR2_Pyrus_Config::current()->preferred_state;
        if (!isset(PEAR2_Pyrus_Config::current()->channelregistry[$parr['channel']])) {
            throw new PEAR2_Pyrus_Package_Exception(
                'Unknown remote channel: ' . $parr['channel']);
        }

        try {
            $chan = PEAR2_Pyrus_Config::current()->channelregistry[$parr['channel']];
        } catch (Exception $e) {
            throw new PEAR2_Pyrus_Package_Exception('Cannot retrieve download information ' .
                'for remote abstract package ' . $parr['channel'] . '/' . $parr['package'], $e);
        }

        $p_mirror = PEAR2_Pyrus_Config::current()->preferred_mirror;
        $mirror   = isset($chan->mirrors[$p_mirror]) ? $chan->mirrors[$p_mirror] : $chan;
        return $mirror->remotepackage[$parr['package']];
    }
}