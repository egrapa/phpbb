<?php
/**
 * This file is part of the ForumHulp extension package.
 *
* @copyright (c) 2015 John Peskens (http://ForumHulp.com)
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace forumhulp\helper;

class helper 
{
	protected $config;
	protected $phpbb_extension_manager;
	protected $template;
	protected $user;
	protected $request;
	protected $log;
	protected $cache;
	protected $root_path;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\extension\manager $phpbb_extension_manager,
		\phpbb\template\template $template,
		\phpbb\user $user,
		\phpbb\request\request $request,
		\phpbb\log\log $log,
		\phpbb\cache\service $cache,
		$root_path)
	{
		$this->config = $config;
		$this->phpbb_extension_manager = $phpbb_extension_manager;
		$this->template = $template;
		$this->user = $user;
		$this->request = $request;
		$this->log = $log;
		$this->cache = $cache;
		$this->root_path = $root_path;

		$this->user->add_lang(array('install', 'acp/extensions', 'migrator'));
	}

	public function detail($ext_name)
	{
		$md_manager = (version_compare($this->config['version'], '3.2.*', '<')) ? 
					new \phpbb\extension\metadata_manager($ext_name, $this->config, $this->phpbb_extension_manager, $this->template, $this->user, $this->root_path) :
					new \phpbb\extension\metadata_manager($ext_name, $this->config, $this->phpbb_extension_manager, $this->template, $this->root_path);
		try
		{
			$this->metadata = $md_manager->get_metadata('all');
		}
		catch(\phpbb\extension\exception $e)
		{
			$message = call_user_func_array(array($this->user, 'lang'), array_merge(array($e->getMessage()), $e->get_parameters()));
			trigger_error($message, E_USER_WARNING);
		}

		$md_manager->output_template_data();

		if (isset($this->user->lang['ext_details']))
		{
			foreach($this->user->lang['ext_details'] as $key => $value)
			{
				foreach($value as $desc)
				{
					$this->template->assign_block_vars($key, array(
						'DESCRIPTION'	=> $desc,
					));
				}
			}
		}
		
		try
		{
			$updates_available = $this->version_check($md_manager, $this->request->variable('versioncheck_force', false));

			$this->template->assign_vars(array(
				'S_UP_TO_DATE'		=> empty($updates_available),
				'S_VERSIONCHECK'	=> true,
				'UP_TO_DATE_MSG'	=> $this->user->lang(empty($updates_available) ? 'UP_TO_DATE' : 'NOT_UP_TO_DATE', $md_manager->get_metadata('display-name')),
			));

			foreach ($updates_available as $branch => $version_data)
			{
				$this->template->assign_block_vars('updates_available', $version_data);
			}
		}
		catch (\RuntimeException $e)
		{
			$this->template->assign_block_vars('note', array(
				'DESCRIPTION'		=> $this->user->lang('VERSIONCHECK_FAIL'),
				'S_VERSIONCHECK'	=> true,
			));
			if ($e->getCode())
			{
				$this->template->assign_block_vars('note', array(
					'DESCRIPTION'		=> $e->getCode(),
					'S_VERSIONCHECK'	=> true,
				));
				$this->template->assign_block_vars('note', array(
					'DESCRIPTION'		=> ($e->getMessage() !== $this->user->lang('VERSIONCHECK_FAIL')) ? $e->getMessage() : '',
					'S_VERSIONCHECK'	=> true,
				));
			}
		}

		if ($this->request->is_ajax())
		{
			$this->template->assign_vars(array(
				'IS_AJAX'	=> true,
			));
		} else
		{
			$this->template->assign_vars(array(
			//	'U_BACK'	=> $this->u_action,
			));
		}
	}

	/**
	* Check the version and return the available updates.
	*
	* @param \phpbb\extension\metadata_manager $md_manager The metadata manager for the version to check.
	* @param bool $force_update Ignores cached data. Defaults to false.
	* @param bool $force_cache Force the use of the cache. Override $force_update.
	* @return string
	* @throws RuntimeException
	*/
	protected function version_check(\phpbb\extension\metadata_manager $md_manager, $force_update = false, $force_cache = false)
	{
		$meta = $md_manager->get_metadata('all');

		if (!isset($meta['extra']['version-check']))
		{
			throw new \RuntimeException($this->user->lang('NO_VERSIONCHECK'), 1);
		}

		$version_check = $meta['extra']['version-check'];

		$version_helper = (version_compare($this->config['version'], '3.1.1', '>')) ? 
						new \phpbb\version_helper($this->cache, $this->config, new \phpbb\file_downloader(), $this->user) :
						new \phpbb\version_helper($this->cache, $this->config, $this->user);

		$version_helper->set_current_version($meta['version']);
		$version_helper->set_file_location($version_check['host'], $version_check['directory'], $version_check['filename']);
		$version_helper->force_stability($this->config['extension_force_unstable'] ? 'unstable' : null);

		return $updates = $version_helper->get_suggested_updates($force_update, $force_cache);
	}

	/**
	* Update files on server
	*
	* @return null
	* @access public
	*/
	public function update_files($replacements = array(), $revert)
	{
		$this->replacements = $replacements;
		$files = $this->replacements['files'];
		$searches = ($revert) ? $this->replacements['replaces'] : $this->replacements['searches'];
		$replace = ($revert) ? $this->replacements['searches'] : $this->replacements['replaces'];
		$i = $j = 0;
		$files_changed = array();
		foreach($files as $key => $file)
		{
			if (is_writable($this->root_path . $file))
			{
				$fp = @fopen($this->root_path . $file , 'r' );
				if ($fp === false)
				{
					continue;
				}
				$content = fread( $fp, filesize($this->root_path . $file) );
				(!$revert) ? copy($this->root_path . $file, $this->root_path . $file . '.bak') : null;
				fclose($fp);
				foreach($searches[$key] as $key2 => $search)
				{
					if ($revert || strpos($content, $replace[$key][$key2]) === false)
					{
						$content = str_replace($search, $replace[$key][$key2], $content);
						($key2 == 0) ? $i++ : $i;
					}
				}
				if ($i != $j)
				{
					$new_file = $files[$key];
					$fp = @fopen($this->root_path . $new_file , 'w' );
					if ($fp === false)
					{
						continue;
					}
					$fwrite = fwrite($fp, $content);
					fclose($fp);
					if ($fwrite !== false)
					{
						$j = $i;
						$files_changed[] = $new_file;
					}
				}
			}
		}

		if (sizeof($files) == sizeof($files_changed))
		{
			$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, (($revert) ? 'LOG_CORE_DEINSTALLED' : 'LOG_CORE_INSTALLED'), time(), array());
		} else
		{
			$not_updated = array_diff($files, $files_changed);
			$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, (($revert) ? 'LOG_CORE_NOT_REPLACED' : 'LOG_CORE_NOT_UPDATED'), time(), array(implode('<br />', $not_updated)));
		}
	}
}