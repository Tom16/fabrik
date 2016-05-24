<?php
/**
 * Fabrik Component Helper
 *
 * @package     Joomla.Administrator
 * @subpackage  Fabrik
 * @copyright   Copyright (C) 2005-2015 fabrikar.com - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 * @since       1.6
 */

namespace Fabrik\Helpers\Admin;

// No direct access
defined('_JEXEC') or die('Restricted access');

use Fabrik\Helpers\Worker;
use Fabrik\Helpers\StringHelper;
use Fabrik\Helpers\Text;
use Joomla\Registry\Registry;
use \JVersion;
use \JFilterInput;
use \JAccess;
use \JFactory;
use \JComponentHelper;
use \JHtmlSidebar;

/**
 * Fabrik Component Helper
 *
 * @package     Joomla
 * @subpackage  Fabrik
 * @since       1.5
 */
class Admin
{
	/**
	 * Prepare the date for saving
	 * DATES SHOULD BE SAVED AS UTC
	 *
	 * @param   string $strDate publish down date
	 *
	 * @return  string
	 */
	public static function prepareSaveDate($strDate)
	{
		$config   = JFactory::getConfig();
		$tzOffset = $config->get('offset');
		$db       = Worker::getDbo(true);

		// Handle never un-publish date
		if (trim($strDate) == Text::_('Never') || trim($strDate) == '' || trim($strDate) == $db->getNullDate())
		{
			$strDate = $db->getNullDate();
		}
		else
		{
			if (StringHelper::strlen(trim($strDate)) <= 10)
			{
				$strDate .= ' 00:00:00';
			}

			$date    = JFactory::getDate($strDate, $tzOffset);
			$strDate = $date->toSql();
		}

		return $strDate;
	}

	/**
	 * Configure the Link bar.
	 *
	 * @param   string $vName The name of the active view.
	 *
	 * @since    1.6
	 *
	 * @return    void
	 */
	public static function addSubmenu($vName)
	{
		$vizUrl = 'index.php?option=com_fabrik&view=visualizations';

		JHtmlSidebar::addEntry(Text::_('COM_FABRIK_SUBMENU_HOME'), 'index.php?option=com_fabrik', $vName == 'home');
		JHtmlSidebar::addEntry(Text::_('COM_FABRIK_SUBMENU_LISTS'), 'index.php?option=com_fabrik&view=lists', $vName == 'lists');
		JHtmlSidebar::addEntry(Text::_('COM_FABRIK_SUBMENU_FORMS'), 'index.php?option=com_fabrik&view=forms', $vName == 'forms');
		JHtmlSidebar::addEntry(Text::_('COM_FABRIK_SUBMENU_GROUPS'), 'index.php?option=com_fabrik&view=groups', $vName == 'groups');
		JHtmlSidebar::addEntry(Text::_('COM_FABRIK_SUBMENU_ELEMENTS'), 'index.php?option=com_fabrik&view=elements', $vName == 'elements');
		JHtmlSidebar::addEntry(Text::_('COM_FABRIK_SUBMENU_VISUALIZATIONS'), $vizUrl, $vName == 'visualizations');
		JHtmlSidebar::addEntry(Text::_('COM_FABRIK_SUBMENU_PACKAGES'), 'index.php?option=com_fabrik&view=packages', $vName == 'packages');
		JHtmlSidebar::addEntry(Text::_('COM_FABRIK_SUBMENU_CONNECTIONS'), 'index.php?option=com_fabrik&view=connections', $vName == 'connections');
		JHtmlSidebar::addEntry(Text::_('COM_FABRIK_SUBMENU_CRONS'), 'index.php?option=com_fabrik&view=crons', $vName == 'crons');
	}

	/**
	 * Applies the content tag filters to arbitrary text as per settings for current user group
	 *
	 * @param   string $text The string to filter
	 *
	 * @return  string  The filtered string
	 */
	public static function filterText($text)
	{
		// Filter settings
		jimport('joomla.application.component.helper');
		$config     = JComponentHelper::getParams('com_config');
		$user       = JFactory::getUser();
		$userGroups = JAccess::getGroupsByUser($user->get('id'));

		$filters = $config->get('filters');

		$blackListTags       = array();
		$blackListAttributes = array();

		$whiteListTags       = array();
		$whiteListAttributes = array();

		$noHtml     = false;
		$whiteList  = false;
		$blackList  = false;
		$unfiltered = false;

		// Cycle through each of the user groups the user is in.
		// Remember they are include in the Public group as well.
		foreach ($userGroups as $groupId)
		{
			// May have added a group by not saved the filters.
			if (!isset($filters->$groupId))
			{
				continue;
			}

			// Each group the user is in could have different filtering properties.
			$filterData = $filters->$groupId;
			$filterType = StringHelper::strtoupper($filterData->filter_type);

			if ($filterType == 'NH')
			{
				// Maximum HTML filtering.
				$noHtml = true;
			}
			elseif ($filterType == 'NONE')
			{
				// No HTML filtering.
				$unfiltered = true;
			}
			else
			{
				// Black or white list.
				// Pre-process the tags and attributes.
				$tags           = explode(',', $filterData->filter_tags);
				$attributes     = explode(',', $filterData->filter_attributes);
				$tempTags       = array();
				$tempAttributes = array();

				foreach ($tags as $tag)
				{
					$tag = trim($tag);

					if ($tag)
					{
						$tempTags[] = $tag;
					}
				}

				foreach ($attributes as $attribute)
				{
					$attribute = trim($attribute);

					if ($attribute)
					{
						$tempAttributes[] = $attribute;
					}
				}

				// Collect the black or white list tags and attributes.
				// Each list is cumulative.
				if ($filterType == 'BL')
				{
					$blackList           = true;
					$blackListTags       = array_merge($blackListTags, $tempTags);
					$blackListAttributes = array_merge($blackListAttributes, $tempAttributes);
				}
				elseif ($filterType == 'WL')
				{
					$whiteList           = true;
					$whiteListTags       = array_merge($whiteListTags, $tempTags);
					$whiteListAttributes = array_merge($whiteListAttributes, $tempAttributes);
				}
			}
		}

		// Remove duplicates before processing (because the black list uses both sets of arrays).
		$blackListTags       = array_unique($blackListTags);
		$blackListAttributes = array_unique($blackListAttributes);
		$whiteListTags       = array_unique($whiteListTags);
		$whiteListAttributes = array_unique($whiteListAttributes);

		// Unfiltered assumes first priority.
		if ($unfiltered)
		{
			// Don't apply filtering.
		}
		else
		{
			// Black lists take second precedence.
			if ($blackList)
			{
				// Remove the white-listed attributes from the black-list.
				$tags   = array_diff($blackListTags, $whiteListTags);
				$attrs  = array_diff($blackListAttributes, $whiteListAttributes);
				$filter = JFilterInput::getInstance($tags, $attrs, 1, 1);
			}
			// White lists take third precedence.
			elseif ($whiteList)
			{
				$filter = JFilterInput::getInstance($whiteListTags, $whiteListAttributes, 0, 0, 0);
			}
			// No HTML takes last place.
			else
			{
				$filter = JFilterInput::getInstance();
			}

			$text = $filter->clean($text, 'html');
		}

		return $text;
	}

	/**
	 * Set the layout based on Joomla version
	 * Allows for loading of new bootstrap admin templates in J3.0+
	 *
	 * @param   \JView &$view current view to setLayout for
	 *
	 * @return  void
	 */
	public static function setViewLayout(&$view)
	{
		// If rendering a list inside a form and viewing in admin - there were layout name conflicts (so renamed bootstrap to admin_bootstrap)
		$layout = $view->getName() === 'list' ? 'admin_bootstrap' : 'bootstrap';
		$view->setLayout($layout);
	}
}
