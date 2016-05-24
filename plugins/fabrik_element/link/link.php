<?php
/**
 * Plugin element to render two fields to capture a link (url/label)
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.element.link
 * @copyright   Copyright (C) 2005-2015 fabrikar.com - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

namespace Fabrik\Plugins\Element;

// No direct access
defined('_JEXEC') or die('Restricted access');

use Fabrik\Helpers\Html;
use Fabrik\Helpers\ArrayHelper;
use \stdClass;
use Fabrik\Helpers\Worker;
use Fabrik\Helpers\StringHelper;
use Fabrik\Helpers\Text;

/**
 * Plugin element to render two fields to capture a link (url/label)
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.element.link
 * @since       3.0
 */
class Link extends Element
{
	/**
	 * Does the element contain sub elements e.g checkboxes radiobuttons
	 *
	 * @var bool
	 */
	public $hasSubElements = true;

	/**
	 * Db table field type
	 *
	 * @var string
	 */
	protected $fieldDesc = 'TEXT';

	/**
	 * Shows the data formatted for the list view
	 *
	 * @param   string    $data      Elements data
	 * @param   stdClass  &$thisRow  All the data in the lists current row
	 * @param   array     $opts      Rendering options
	 *
	 * @return  string	formatted value
	 */
	public function renderListData($data, stdClass &$thisRow, $opts = array())
	{
		$listModel = $this->getlistModel();
		$params = $this->getParams();
		$target = $params->get('link_target', '');
		$smart_link = $params->get('link_smart_link', false);

		if ($listModel->getOutPutFormat() != 'rss' && ($smart_link || $target == 'mediabox'))
		{
			Html::slimbox();
		}

		$data = Worker::JSONtoData($data, true);

		if (!empty($data))
		{
			if (array_key_exists('label', $data))
			{
				$data = (array) $this->_renderListData($data, $thisRow);
			}
			else
			{
				for ($i = 0; $i < count($data); $i++)
				{
					$data[$i] = ArrayHelper::fromObject($data[$i]);
					$data[$i] = $this->_renderListData($data[$i], $thisRow);
				}
			}
		}

		$data = json_encode($data);

		return parent::renderListData($data, $thisRow, $opts);
	}

	/**
	 * Render Individual parts of the cell data.
	 * Called from renderListData();
	 *
	 * @param   string  $data     cell data
	 * @param   object  $thisRow  the data in the lists current row
	 *
	 * @return  string  formatted value
	 */

	protected function _renderListData($data, $thisRow)
	{
		$w = new Worker;

		if (is_string($data))
		{
			$data = Worker::JSONtoData($data, true);
		}

		$listModel = $this->getlistModel();
		$params = $this->getParams();

		if (is_array($data))
		{
			if (count($data) == 1)
			{
				$data['label'] = ArrayHelper::getValue($data, 'link');
			}

			$href = trim($data['link']);
			$lbl = trim($data['label']);
			$href = $w->parseMessageForPlaceHolder(urldecode($href), ArrayHelper::fromObject($thisRow));

			if (StringHelper::strtolower($href) == 'http://' || StringHelper::strtolower($href) == 'https://')
			{
				// Treat some default values as empty
				$href = '';
			}
			else if (strlen($href) > 0 && substr($href, 0, 1) != "/"
				&& substr(StringHelper::strtolower($href), 0, 7) != 'http://'
				&& substr(StringHelper::strtolower($href), 0, 8) != 'https://'
				&& substr(StringHelper::strtolower($href), 0, 6) != 'ftp://'
				)
			{
					$href = 'http://' . $href;
			}
			// If used as a icon - the dom parser needs to use &amp; and not & in url querystrings
			if (!strstr($href, '&amp;'))
			{
				$href = str_replace('&', '&amp;', $href);
			}

			if ($listModel->getOutPutFormat() != 'rss')
			{
				$opts['smart_link'] = $params->get('link_smart_link', false);
				$opts['rel'] = $params->get('rel', '');
				$opts['target'] = $params->get('link_target', '');
				$title = $params->get('link_title', '');

				if ($title !== '')
				{
					$opts['title'] = strip_tags($w->parseMessageForPlaceHolder($title, $data));
				}

				return Html::a($href, $lbl, $opts);
			}
			else
			{
				$link = $href;
			}

			$aRow = ArrayHelper::fromObject($thisRow);
			$link = $listModel->parseMessageForRowHolder($link, $aRow);

			return $link;
		}

		return $data;
	}

	/**
	 * Prepares the element data for CSV export
	 *
	 * @param   string  $data      Element data
	 * @param   object  &$thisRow  All the data in the lists current row
	 *
	 * @return  string	Formatted CSV export value
	 */

	public function renderListData_csv($data, &$thisRow)
	{
		$o = json_decode($data);

		return isset($o->link) ? $o->link : '';
	}

	/**
	 * Draws the html form element
	 *
	 * @param   array  $data           to pre-populate element with
	 * @param   int    $repeatCounter  repeat group counter
	 *
	 * @return  string	elements html
	 */

	public function render($data, $repeatCounter = 0)
	{
		$name = $this->getHTMLName($repeatCounter);
		$id = $this->getHTMLId($repeatCounter);
		$params = $this->getParams();
		$bits = $this->inputProperties($repeatCounter);
		$value = $this->getValue($data, $repeatCounter);
		$opts = array();

		if ($value == '')
		{
			$value = array('label' => '', 'link' => '');
		}
		else
		{
			if (!is_array($value))
			{
				$value = Worker::JSONtoData($value, true);
				/**
				 * In some legacy case, data is like ...
				 * [{"label":"foo","link":"bar"}]
				 * ... I think if it came from 2.1.  So lets check to see if we need
				 * to massage that into the right format
				 */
				if (array_key_exists(0, $value) && is_object($value[0]))
				{
					$value = ArrayHelper::fromObject($value[0]);
				}
				elseif (array_key_exists(0, $value))
				{
					$value['label'] = $value[0];
				}
			}
		}

		if (count($value) == 0)
		{
			$value = array('label' => '', 'link' => '');
		}

		if (Worker::getMenuOrRequestVar('rowid') == 0 && ArrayHelper::getValue($value, 'link', '') === '')
		{
			$value['link'] = $params->get('link_default_url');
		}

		if (!$this->isEditable())
		{
			$lbl = trim(ArrayHelper::getValue($value, 'label'));
			$href = trim(ArrayHelper::getValue($value, 'link'));
			$w = new Worker;
			$href = is_array($data) ? $w->parseMessageForPlaceHolder($href, $data) : $w->parseMessageForPlaceHolder($href);

			$opts['target'] = trim($params->get('link_target', ''));
			$opts['smart_link'] = $params->get('link_smart_link', false);
			$opts['rel'] = $params->get('rel', '');
			$title = $params->get('link_title', '');

			if ($title !== '')
			{
				$opts['title'] = strip_tags($w->parseMessageForPlaceHolder($title, $data));
			}

			return Html::a($href, $lbl, $opts);
		}

		$labelname = StringHelper::rtrimword($name, '[]') . '[label]';
		$linkname = StringHelper::rtrimword($name, '[]') . '[link]';

		$bits['name'] = $labelname;
		$bits['placeholder'] = Text::_('PLG_ELEMENT_LINK_LABEL');
		$bits['value'] = $value['label'];
		$bits['class'] .= ' fabrikSubElement';
		unset($bits['id']);

		$layout = $this->getLayout('form');
		$layoutData = new stdClass;
		$layoutData->id = $id;
		$layoutData->name = $name;
		$layoutData->linkAttributes = $bits;

		$bits['placeholder'] = Text::_('PLG_ELEMENT_LINK_URL');
		$bits['name'] = $linkname;
		$bits['value'] = ArrayHelper::getValue($value, 'link');

		if (is_a($bits['value'], 'stdClass'))
		{
			$bits['value'] = $bits['value']->{0};
		}

		$layoutData->labelAttributes = $bits;

		return $layout->render($layoutData);
	}

	/**
	 * Turn form value into email formatted value
	 *
	 * @param   mixed  $value          Element value
	 * @param   array  $data           Form data
	 * @param   int    $repeatCounter  Group repeat counter
	 *
	 * @return  string  email formatted value
	 */

	protected function getIndEmailValue($value, $data = array(), $repeatCounter = 0)
	{
		if (is_string($value))
		{
			$value = Worker::JSONtoData($value, true);
			$value['label'] = ArrayHelper::getValue($value, 0);
			$value['link'] = ArrayHelper::getValue($value, 1);
		}

		if (is_array($value))
		{
			$w = new Worker;
			$link = $w->parseMessageForPlaceHolder($value['link']);
			$value = '<a href="' . $link . '" >' . $value['label'] . '</a>';
		}

		return $value;
	}

	/**
	 * Manipulates posted form data for insertion into database
	 *
	 * @param   mixed  $val   this elements posted form data
	 * @param   array  $data  posted form data
	 *
	 * @return  mixed
	 */

	public function storeDatabaseFormat($val, $data)
	{
		/* $$$ hugh - added 'normalization' of links, to add http:// if no :// in the link.
		* not sure if we really want to do it here, or only when rendering?
		* $$$ hugh - quit normalizing links.
		*/
		$params = $this->getParams();

		if (is_array($val))
		{
			if ($params->get('use_bitly'))
			{
				require_once JPATH_SITE . '/components/com_fabrik/libs/bitly/bitly.php';
				$login = $params->get('bitly_login');
				$key = $params->get('bitly_apikey');
				$bitly = new \bitly($login, $key);
			}

			foreach ($val as $key => &$v)
			{
				if (is_array($v))
				{
					if ($params->get('use_bitly'))
					{
						/* bitly will return an error if you try and shorten a shortened link,
						 * and the class file we are using doesn't check for this
						 */
						if (!strstr($v['link'], 'bit.ly/') && $v['link'] !== '')
						{
							$v['link'] = (string) $bitly->shorten($v['link']);
						}
					}
				}
				else
				{
					if ($key == 'link')
					{
						$v = StringHelper::encodeurl($v);
					}
					// Not in repeat group
					if ($key == 'link' && $params->get('use_bitly'))
					{
						if (!strstr($v, 'bit.ly/') && $v !== '')
						{
							$v = (string) $bitly->shorten($v);
						}
					}
				}
			}
		}
		else
		{
			if (json_decode($val))
			{
				return $val;
			}
		}

		$return = json_encode($val);

		return $return;
	}

	/**
	 * Returns javascript which creates an instance of the class defined in formJavascriptClass()
	 *
	 * @param   int  $repeatCounter  Repeat group counter
	 *
	 * @return  array
	 */

	public function elementJavascript($repeatCounter)
	{
		$listModel = $this->getlistModel();
		$params = $this->getParams();
		$target = $params->get('link_target', '');
		$smart_link = $params->get('link_smart_link', false);

		if ($listModel->getOutPutFormat() != 'rss' && ($smart_link || $target == 'mediabox'))
		{
			Html::slimbox();
		}

		$id = $this->getHTMLId($repeatCounter);
		$opts = $this->getElementJSOptions($repeatCounter);

		return array('FbLink', $id, $opts);
	}

	/**
	 * Called by form model to build an array of values to encrypt
	 *
	 * @param   array  &$values  previously encrypted values
	 * @param   array  $data     form data
	 * @param   int    $c        repeat group counter
	 *
	 * @return  void
	 */

	public function getValuesToEncrypt(&$values, $data, $c)
	{
		$name = $this->getFullName(true, false);
		$group = $this->getGroup();

		if ($group->canRepeat())
		{
			// $$$ rob - I've not actually tested this bit!
			if (!array_key_exists($name, $values))
			{
				$values[$name]['data']['label'] = array();
				$values[$name]['data']['link'] = array();
			}

			$values[$name]['data']['label'][$c] = ArrayHelper::getValue($data, 'label');
			$values[$name]['data']['link'][$c] = ArrayHelper::getValue($data, 'link');
		}
		else
		{
			$values[$name]['data']['label'] = ArrayHelper::getValue($data, 'label');
			$values[$name]['data']['link'] = ArrayHelper::getValue($data, 'link');
		}
	}

	/**
	 * This really does get just the default value (as defined in the element's settings)
	 *
	 * @param   array  $data  form data
	 *
	 * @return mixed
	 */

	public function getDefaultValue($data = array())
	{
		if (!isset($this->default))
		{
			$w = new Worker;
			$params = $this->getParams();
			$link = $params->get('link_default_url');
			/* $$$ hugh - no idea what this was here for, but it was causing some BIZARRE bugs!
			*$formdata = $this->getForm()->getData();
			* $$$ rob only parse for place holder if we can use the element
			* otherwise for encrypted values store raw, and they are parsed when the
			* form is processed in form::addEncrytedVarsToArray();
			*/
			if ($this->canUse())
			{
				$link = $w->parseMessageForPlaceHolder($link, $data);
			}

			$element = $this->getElement();
			$default = $w->parseMessageForPlaceHolder($element->default, $data);

			if ($element->eval == "1")
			{
				$default = @eval((string) stripslashes($default));
			}

			$this->default = array('label' => $default, 'link' => $link);
		}

		return $this->default;
	}

	/**
	 * Does the element consider the data to be empty
	 * Used in isempty validation rule
	 *
	 * @param   array  $data           data to test against
	 * @param   int    $repeatCounter  repeat group #
	 *
	 * @return  bool
	 */

	public function dataConsideredEmpty($data, $repeatCounter)
	{
		if (is_array($data))
		{
			foreach ($data as &$d)
			{
				$d = strip_tags($d);
			}

			$link = ArrayHelper::getValue($data, 'link', '');

			return $link === '' || $link === 'http://';
		}

		$data = strip_tags($data);

		if (trim($data) == '' || $data == '<a target="_self" href=""></a>')
		{
			return true;
		}

		return false;
	}
}
