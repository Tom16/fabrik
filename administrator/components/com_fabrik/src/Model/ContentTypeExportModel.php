<?php
/**
 * Fabrik Admin Content Type Export Model
 *
 * @package     Joomla.Administrator
 * @subpackage  Fabrik
 * @copyright   Copyright (C) 2005-2016  Media A-Team, Inc. - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 * @since       3.4
 */

namespace Joomla\Component\Fabrik\Administrator\Model;

// No direct access
defined('_JEXEC') or die('Restricted access');

use Fabrik\Helpers\Worker;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Path;
use Joomla\Component\Fabrik\Administrator\Database\Mysqli\MysqliExporter;
use Joomla\Component\Fabrik\Administrator\Helper\ContentTypeHelper;
use Joomla\Component\Fabrik\Administrator\Table\FabTable;
use Joomla\Component\Fabrik\Administrator\Table\JoinTable;
use Joomla\Component\Fabrik\Site\Model\FormModel;
use Joomla\Database\Mysqli\MysqliImporter as BaseMysqliImporter;
use Joomla\Utilities\ArrayHelper;
use \Joomla\Registry\Registry;

/**
 * Fabrik Admin Content Type Export Model
 *
 * @package     Joomla.Administrator
 * @subpackage  Fabrik
 * @since       4.0
 */
class ContentTypeExportModel extends FabAdminModel
{
	/**
	 * Include paths for searching for Content type XML files
	 *
	 * @var    array
	 *
	 * @since 4.0
	 */
	private static $_contentTypeIncludePaths = array();

	/**
	 * Content type DOM document
	 *
	 * @var \DOMDocument
	 *
	 * @since 4.0
	 */
	private $doc;

	/**
	 * Admin List model
	 *
	 * @var ListModel
	 *
	 * @since 4.0
	 */
	private $listModel;

	/**
	 * Plugin names that we can not use in a content type
	 *
	 * @var array
	 *
	 * @since 4.0
	 */
	private $invalidPlugins = array('cascadingdropdown');

	/**
	 * Plugin names that require an import/export of a database table.
	 *
	 * @var array
	 *
	 * @since 4.0
	 */
	private $pluginsWithTables = array('databasejoin');

	/**
	 * This site's view levels
	 *
	 * @var array
	 *
	 * @since 4.0
	 */
	private $viewLevels;

	/**
	 * This site's groups
	 *
	 * @var array
	 *
	 * @since 4.0
	 */
	private $groups;

	/**
	 * Exported tables
	 *
	 * @var array
	 *
	 * @since 4.0
	 */
	private static $exportedTables = array();

	/**
	 * Constructor.
	 *
	 * @param   array $config An optional associative array of configuration settings.
	 *
	 * @throws \UnexpectedValueException
	 *
	 * @since 4.0
	 */
	public function __construct($config = array())
	{
		parent::__construct($config);
		$listModel = ArrayHelper::getValue($config, 'listModel', FabModel::getInstance(ListModel::class));

		if (!is_a($listModel, 'FabrikAdminModelList'))
		{
			throw new \UnexpectedValueException('Content Type Constructor requires an Admin List Model');
		}

		$this->listModel               = $listModel;
		$this->doc                     = new \DOMDocument();
		$this->doc->preserveWhiteSpace = false;
		$this->doc->formatOutput       = true;
	}

	/**
	 * Method to get the select content type form.
	 *
	 * @param   array $data     Data for the form.
	 * @param   bool  $loadData True if the form is to load its own data (default case), false if not.
	 *
	 * @return  mixed  A JForm object on success, false on failure
	 *
	 * @since    4.0
	 */
	public function getForm($data = array(), $loadData = true)
	{
		// Get the form.
		$form = $this->loadForm('com_fabrik.content-type', 'content-type', array('control' => 'jform', 'load_data' => $loadData));

		if (empty($form))
		{
			return false;
		}

		return $form;
	}

	/**
	 * Load in a content type
	 *
	 * @param   string $name File name
	 *
	 * @throws \UnexpectedValueException
	 *
	 * @return $this  Allows for chaining
	 *
	 * @since 4.0
	 */
	public function loadContentType($name)
	{
		if ((string) $name === '')
		{
			throw new \UnexpectedValueException('no content type supplied');
		}
		$paths = self::addContentTypeIncludePath();
		$path  = Path::find($paths, $name);

		if (!$path)
		{
			throw new \UnexpectedValueException('Content type not found in paths');
		}

		$xml = file_get_contents($path);
		$this->doc->loadXML($xml);

		return $this;
	}

	/**
	 * Add a filesystem path where content type XML files should be searched for.
	 * You may either pass a string or an array of paths.
	 *
	 * @param   mixed $path A filesystem path or array of filesystem paths to add.
	 *
	 * @return  array  An array of filesystem paths to find Content type XML files.
	 *
	 * @since 4.0
	 */
	public static function addContentTypeIncludePath($path = null)
	{
		// If the internal paths have not been initialised, do so with the base table path.
		if (empty(self::$_contentTypeIncludePaths))
		{
			self::$_contentTypeIncludePaths = JPATH_COMPONENT_ADMINISTRATOR . '/models/content_types';
		}

		// Convert the passed path(s) to add to an array.
		settype($path, 'array');

		// If we have new paths to add, do so.
		if (!empty($path))
		{
			// Check and add each individual new path.
			foreach ($path as $dir)
			{
				// Sanitize path.
				$dir = trim($dir);

				// Add to the front of the list so that custom paths are searched first.
				if (!in_array($dir, self::$_contentTypeIncludePaths))
				{
					array_unshift(self::$_contentTypeIncludePaths, $dir);
				}
			}
		}

		return self::$_contentTypeIncludePaths;
	}

	/**
	 * Create the content type
	 * Save it to /administrator/components/com_fabrik/models/content_types
	 * Update form model with content type path
	 *
	 * @param   FormModel $formModel
	 *
	 * @return  bool
	 *
	 * @since 4.0
	 */
	public function create(FormModel $formModel)
	{
		// We don't want to export the main table, as a new one is created when importing the content type
		$this->listModel = $formModel->getListModel();
		$mainTable       = $this->listModel->getTable()->get('db_table_name');
		$mainTableConnection = $this->listModel->getTable()->get('connection_id');
		$contentType     = $this->doc->createElement('contenttype');
		$tables          = ContentTypeHelper::iniTableXML($this->doc, $mainTable);

		$label = File::makeSafe($formModel->getForm()->get('label'));
		$name  = $this->doc->createElement('name', $label);
		$contentType->appendChild($name);
		$contentType->appendChild($this->version());
		$groups = $formModel->getGroupsHiarachy();

		foreach ($groups as $groupModel)
		{
			$groupData     = $groupModel->getGroup()->getProperties();
			$elements      = array();
			$elementModels = $groupModel->getMyElements();

			foreach ($elementModels as $elementModel)
			{
				$elements[] = $elementModel->getElement()->getProperties();
			}

			$contentType->appendChild($this->createFabrikGroupXML($groupData, $elements, $tables, $mainTable, $mainTableConnection));
		}

		$contentType->appendChild($tables);
		$contentType->appendChild($this->createViewLevelXML());
		$contentType->appendChild($this->createGroupXML());
		$this->doc->appendChild($contentType);
		$xml  = $this->doc->saveXML();
		$path = JPATH_COMPONENT_ADMINISTRATOR . '/models/content_types/' . $label . '.xml';

		if (File::write($path, $xml))
		{
			$form   = $formModel->getForm();
			$params = $formModel->getParams();
			$params->set('content_type_path', $path);
			$form->params = $params->toString();

			return $form->save($form->getProperties());
		}

		return false;
	}

	/**
	 * Get the current Fabrik version in a DOMElement
	 *
	 * @return \DOMElement
	 *
	 * @since 4.0
	 */
	private function version()
	{
		$xml     = simplexml_load_file(JPATH_COMPONENT_ADMINISTRATOR . '/fabrik.xml');
		$version = $this->doc->createElement('fabrikversion', (string) $xml->version);

		return $version;
	}

	/**
	 * Create group XML
	 *
	 * @param array      $data     Group data
	 * @param array      $elements Element data
	 * @param \DomElement $tables
	 * @param string     $mainTable
	 *
	 * @return \DOMElement
	 *
	 * @since 4.0
	 */
	private function createFabrikGroupXML($data, $elements, $tables, $mainTable = '', $mainTableConnection=1)
	{
		$tableParams = array('table_join', 'join_from_table');

		$group = ContentTypeHelper::buildExportNode($this->doc, 'group', $data);

		if ($data['is_join'] === '1')
		{
			$join = FabTable::getInstance(JoinTable::class);
			$join->load($data['join_id']);

			foreach ($tableParams as $tableParam)
			{
				if ($join->get($tableParam) !== $mainTable)
				{
					$this->createTableXML($tables, $join->get($tableParam), $mainTableConnection);
				}
			}

			$groupJoin = ContentTypeHelper::buildExportNode($this->doc, 'join', $join->getProperties(), array('id'));
			$group->appendChild($groupJoin);
		}

		foreach ($elements as $element)
		{
			$group->appendChild($this->createFabrikElementXML($element, $tables, $mainTable));
		}

		return $group;
	}

	/**
	 * Create element XML
	 *
	 * @param   array      $data Element data
	 * @param   \DomElement $tables
	 * @param   string     $mainTable
	 *
	 * @return \DOMElement
	 *
	 * @since 4.0
	 */
	private function createFabrikElementXML($data, $tables, $mainTable)
	{
		if (in_array($data['plugin'], $this->invalidPlugins))
		{
			throw new \UnexpectedValueException('Sorry we can not create content types with ' .
				$data['plugin'] . ' element plugins');
		}

		if (in_array($data['plugin'], $this->pluginsWithTables))
		{
			$params = new Registry($data['params']);

			if ($params->get('join_db_name') !== $mainTable)
			{
				$this->createTableXML($tables, $params->get('join_db_name'),$params->get('join_conn_id'));
			}
		};

		$element       = ContentTypeHelper::buildExportNode($this->doc, 'element', $data);
		$pluginManager = Worker::getPluginManager();
		$elementModel  = clone($pluginManager->getPlugIn($data['plugin'], 'element'));

		if (is_a($elementModel, 'PlgFabrik_ElementDatabasejoin'))
		{
			$join = FabTable::getInstance('Join', 'FabrikTable');
			$join->load(array('element_id' => $data['id']));
			$elementJoin = ContentTypeHelper::buildExportNode($this->doc, 'join', $join->getProperties(), array('id'));
			$element->appendChild($elementJoin);
		}

		return $element;
	}

	/**
	 * Create XML for table export
	 *
	 * @param   \DOMElement $tables    Parent node to attach xml to
	 * @param   string     $tableName Table name to export
	 *
	 * @throws \Exception
	 *
	 * @since 4.0
	 */
	private function createTableXML(&$tables, $tableName,$tableConnId = 1)
	{
		if (in_array($tableName, self::$exportedTables))
		{
			return;
		}

		self::$exportedTables[] = $tableName;
		//$exporter    = $this->db->getExporter();

		$tabDbo= Worker::getDbo(false, $tableConnId);

		// Until the J! exporters are fixed, we only handle Mysqli (with out extended class)
		if (!($tabDbo instanceof BaseMysqliImporter))
		{
			throw new \Exception('Sorry, we currently only support the Mysqli database driver for export');
		}

		$exporter = new MysqliExporter();

		$exporter->setDbo($tabDbo);
		$exporter->from($tableName);
		$tableDoc = new \DOMDocument();
		$xml = (string) $exporter;

		// magic __toString can't throw exceptions, so we've overridden it, and store the exception
		if (empty($xml) && $exporter->exception !== null)
		{
			throw new \Exception('An error occurred in XML export: ' . $exporter->exception->getMessage());
		}

		$tableDoc->loadXML($xml);
		$structures = $tableDoc->getElementsByTagName('table_structure');

		foreach ($structures as $table)
		{
			$table = $this->doc->importNode($table, true);
			$tables->appendChild($table);
		}
	}

	/**
	 * Create the view levels ACL info
	 *
	 * @return \DOMElement
	 *
	 * @since 4.0
	 */
	private function createViewLevelXML()
	{
		$rows       = $this->getViewLevels();
		$viewLevels = $this->doc->createElement('viewlevels');

		foreach ($rows as $row)
		{
			$viewLevel = ContentTypeHelper::buildExportNode($this->doc, 'viewlevel', $row);
			$viewLevels->appendChild($viewLevel);
		}

		return $viewLevels;
	}

	/**
	 * Create the group ACL info
	 *
	 * @return \DOMElement
	 *
	 * @since 4.0
	 */
	private function createGroupXML()
	{
		$rows   = $this->getGroups();
		$groups = $this->doc->createElement('groups');

		foreach ($rows as $row)
		{
			$group = $this->doc->createElement('group');

			foreach ($row as $key => $value)
			{
				$group->setAttribute($key, $value);
			}
			$groups->appendChild($group);
		}

		return $groups;
	}

	/**
	 * Get the site's view levels
	 *
	 * @return array|mixed
	 *
	 * @since 4.0
	 */
	private function getViewLevels()
	{
		if (isset($this->viewLevels))
		{
			return $this->viewLevels;
		}

		$query = $this->db->getQuery(true);
		$query->select('*')->from('#__viewlevels');
		$this->viewLevels = $this->db->setQuery($query)->loadAssocList();

		return $this->viewLevels;
	}

	/**
	 * @return array|mixed
	 *
	 * @since 4.0
	 */
	private function getGroups()
	{
		if (isset($this->groups))
		{
			return $this->groups;
		}

		$query = $this->db->getQuery(true);
		$query->select('*')->from('#__usergroups');
		$this->groups = $this->db->setQuery($query)->loadAssocList('id');

		return $this->groups;
	}

	/**
	 * Download the content type
	 *
	 * @param   FormModel $formModel
	 *
	 * @throws \Exception
	 *
	 * @since 4.0
	 */
	public function download(FormModel $formModel)
	{
		$params  = $formModel->getParams();
		$file    = $params->get('content_type_path');
		$label   = 'content-type-' . $formModel->getForm()->get('label');
		$label   = File::makeSafe($label);
		$zip     = new \ZipArchive;
		$zipFile = $this->config->get('tmp_path') . '/' . $label . '.zip';
		$zipRes  = $zip->open($zipFile, \ZipArchive::CREATE);

		if (!$zipRes)
		{
			throw new \Exception('unable to create ZIP');
		}

		if (!File::exists($file))
		{
			throw new \Exception('Content type file not found');
		}

		if (!$zip->addFile($file, basename($file)))
		{
			throw new \Exception('unable to add file ' . $file . ' to zip');
		}

		$zip->close();
		header('Content-Type: application/zip');
		header('Content-Length: ' . filesize($zipFile));
		header('Content-Disposition: attachment; filename="' . basename($zipFile) . '"');
		echo file_get_contents($zipFile);

		// Must exit to produce valid Zip download
		exit;
	}

	/**
	 * Create content type XML from an array of group/element data
	 * Used in CSV import
	 *
	 * @param   array $groupData
	 * @param   array $elements
	 *
	 * @return string
	 *
	 * @since 4.0
	 */
	public function createXMLFromArray($groupData, $elements)
	{
		$contentType = $this->doc->createElement('contenttype');
		$mainTable   = $this->listModel->getTable()->get('db_table_name');
		$tables      = ContentTypeHelper::iniTableXML($this->doc, $mainTable);
		$name        = $this->doc->createElement('name', 'tmp');
		$contentType->appendChild($name);
		$contentType->appendChild($this->createFabrikGroupXML($groupData, $elements, $tables));
		$contentType->appendChild($tables);
		$contentType->appendChild($this->createViewLevelXML());
		$contentType->appendChild($this->createGroupXML());
		$this->doc->appendChild($contentType);

		return $this->doc->saveXML();
	}

}
