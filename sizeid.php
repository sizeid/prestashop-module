<?php
// cause fuck namespaces!!
require_once __DIR__ . '/bootstrap.php';

class SizeID extends Module
{

	const SIZEID_IDENTITY_KEY = 'SIZEID_IDENTITY_KEY';
	const SIZEID_API_SECURE_KEY = 'SIZEID_API_SECURE_KEY';
	const SIZEID_BUTTON_TEMPLATE = 'SIZEID_BUTTON_TEMPLATE';
	const SIZEID_IMPORT_FILE = 'SIZEID_IMPORT_FILE';
	const SIZEID_EXPORT_FILE = 'SIZEID_EXPORT_FILE';
	const SUBMIT_CREDENTIALS = 'credentials';
	const SUBMIT_CONFIGURATION = 'configuration';
	const SUBMIT_IMPORT = 'import';
	const SUBMIT_EXPORT = 'export';

	public function __construct()
	{
		$this->name = 'sizeid';
		$this->tab = 'front_office_features';
		$this->version = trim(@file_get_contents(__DIR__ . '/build-version'));
		$this->author = 'SizeID s.r.o.';
		$this->need_instance = 1;
		$this->ps_versions_compliancy = ['min' => '1.6', 'max' => _PS_VERSION_];
		$this->bootstrap = TRUE;
		parent::__construct();
		$this->displayName = $this->l('SizeID');
		$this->description = $this->l('Add SizeID Advisor to your Clothing and Footwear offer and give your customers an opportunity to easily find out proper size to order. You will gain more orders, less returns and you will not have to deal with customers queries concerning choosing right size.');
		$this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
		if (!$this->getIdentityKey() || !$this->getApiSecureKey()) {
			$this->warning = $this->l('Identity Key and API Secure key is required.');
		}
	}

	public function install()
	{
		return parent::install() &&
			Database::install() &&
			$this->registerHook('productActions') &&
			$this->registerHook('header') &&
			$this->registerHook('displayAdminProductsExtra') &&
			$this->registerHook('actionProductUpdate');
	}

	public function uninstall()
	{
		return parent::uninstall() && Database::uninstall();
	}

	public function hookDisplayHeader()
	{
		if ($this->getIdentityKey()) {
			return $this->getSizeIDHelper()->renderConnect();
		}
	}

	public function hookProductActions()
	{
		return $this->renderButton();
	}

	private function renderButton()
	{
		$sizeChartId = $this->loadSizeChartId();
		if ($sizeChartId) {
			$this->context->controller->addJS($this->_path . 'views/js/button.js');
			$this->context->controller->addCSS($this->_path . 'views/css/button.css');
			$button = $this->getButton();
			$button
				->setLanguage($this->context->language->iso_code)
				->setSizeChart($sizeChartId);
			return '<div class="sizeid-button-wrap">' . $this->getSizeIDHelper()->renderButton($button) . '</div>';
		}
	}

	public function hookActionProductUpdate()
	{
		$id_product = $this->getProductId();
		$size_chart_id = (int)$_POST['sizeid_size_chart_id'];
		if ($size_chart_id) {
			Database::execute(
				"INSERT INTO `:table_name` (`id_product`, `size_chart_id`) VALUES (:id_product, :size_chart_id) ON DUPLICATE KEY UPDATE `size_chart_id` = :size_chart_id;",
				[
					'table_name' => Database::getTableName(),
					'id_product' => $id_product,
					'size_chart_id' => $size_chart_id,
				]
			);
		} else {
			Database::execute(
				"DELETE FROM `:table_name` WHERE `id_product` = :id_product",
				[
					'table_name' => Database::getTableName(),
					'id_product' => $id_product,
				]
			);
		}
	}

	public function hookDisplayAdminProductsExtra()
	{
		$this->context->smarty->assign(
			[
				'sizeid_size_chart_id' => $this->loadSizeChartId(),
				'sizeid_size_chart_options' => $this->getSizeIDHelper()->getActiveSizeChartsPairs(),
			]
		);
		return $this->display(__FILE__, 'views/templates/admin/sizeid.tpl');
	}

	public function getContent()
	{
		$html = $this->display(__FILE__, 'infos.tpl');
		if (Tools::isSubmit(self::SUBMIT_CREDENTIALS)) {
			$identityKey = Tools::getValue(self::SIZEID_IDENTITY_KEY);
			$apiSecureKey = Tools::getValue(self::SIZEID_API_SECURE_KEY);
			$sizeIDHelper = $this->createSizeIDHelper($identityKey, $apiSecureKey);
			if ($sizeIDHelper->credentialsAreValid()) {
				Configuration::updateValue(self::SIZEID_IDENTITY_KEY, $identityKey);
				Configuration::updateValue(self::SIZEID_API_SECURE_KEY, $apiSecureKey);
				$defaultButton = $sizeIDHelper->getDefaultButton();
				Configuration::updateValue(self::SIZEID_BUTTON_TEMPLATE, serialize($defaultButton->getTemplate()));
				$html .= $this->displayConfirmation($this->l('Client credentials updated.'));
			} else {
				$html .= $this->displayError($this->l('Client credentials are invalid.'));
			}
		}
		if (Tools::isSubmit(self::SUBMIT_CONFIGURATION)) {
			$buttonId = Tools::getValue(self::SIZEID_BUTTON_TEMPLATE);
			$button = $this->getSizeIDHelper()->getButtonById($buttonId);
			Configuration::updateValue(self::SIZEID_BUTTON_TEMPLATE, serialize($button->getTemplate()));
			$html .= $this->displayConfirmation($this->l('Configuration updated.'));
		}
		if (Tools::isSubmit(self::SUBMIT_EXPORT)) {
			$this->createExport();
		}
		if (Tools::isSubmit(self::SUBMIT_IMPORT)) {
			try {
				list($importCount, $errors) = $this->processImportForm(Tools::fileAttachment(self::SIZEID_IMPORT_FILE, FALSE));
				if ($importCount > 0) {
					$html .= $this->displayConfirmation(
						$this->l('Import success.  Imported object count:') . ' ' . $importCount
					);
				}
				if (count($errors) > 0) {
					$html .= $this->displayWarning($this->l('Some objects weren\'t imported.') . '<br>' . implode("<br>", $errors));
				}
			} catch (SizeIDInvalidInputException $ex) {
				$html .= $this->displayError($this->l($ex->getMessage()));
			}
		}
		$html .= $this->generateCredentialsForm();
		if ($this->getSizeIDHelper()->credentialsAreValid()) {
			$html .= $this->generateConfigurationForm();
			$html .= $this->generateExportForm();
			$html .= $this->generateImportForm();
		}
		return $html;
	}

	private function createExport()
	{
		$this->createAndDownloadCsv($this->getExportData());
	}

	private function getExportData()
	{
		$lang = Configuration::get('PS_LANG_DEFAULT');
		$products = Database::query(
			'
				SELECT :p_product.id_product as id_product, size_chart_id as size_chart_id, :p_product_lang.name as product_name
				FROM :p_product
				LEFT JOIN :p_product_sizeid 
				ON :p_product.id_product = :p_product_sizeid.id_product
				LEFT JOIN :p_product_lang 
				ON :p_product.id_product = :p_product_lang.id_product
				',
			[
				'p_' => _DB_PREFIX_,
				'id_lang' => Configuration::get('PS_LANG_DEFAULT'),
			]
		);
		foreach ($products as &$product) {
			$categories = Product::getProductCategoriesFull($product['id_product'], $lang);
			$product['categories'] = $this->flattenCategories($categories);
		}
		return $products;
	}

	private function flattenCategories($categories)
	{
		return implode(
			' > ',
			array_map(
				function ($category) {
					return $category['name'];
				},
				$categories
			)
		);
	}

	private function createAndDownloadCsv($csvLines)
	{
		$f = fopen('php://memory', 'w');
		if (isset($csvLines[0])) {
			fputcsv($f, array_keys($csvLines[0]));
		}
		foreach ($csvLines as $line) {
			fputcsv($f, $line);
		}
		fseek($f, 0);
		header('Content-Type: application/csv');
		header('Content-Disposition: attachment; filename="sizeid-export.csv";');
		fpassthru($f);
		exit(0);
	}

	private function generateConfigurationForm()
	{
		$fields_form[1]['form'] = [
			'legend' => [
				'title' => $this->l('Configuration'),
				'icon' => 'icon-gears',
			],
			'input' => [
				[
					'type' => 'select',
					'name' => self::SIZEID_BUTTON_TEMPLATE,
					'label' => $this->l('Button template'),
					'desc' => $this->l('In SizeID for Business interface you can easily create and predefine your own custom style buttons to fit the best your e-shop‘s appearance. Select one of predefined button styles here.'),
					'options' => [
						'query' => $this->getSizeIDHelper()->getButtons(),
						'id' => 'id',
						'name' => 'name',
					],
				],
			],
			'submit' => [
				'title' => $this->l('Save'),
			],
		];
		$helper = $this->createFormHelper();
		$helper->fields_value[self::SIZEID_BUTTON_TEMPLATE] = $this->getButton()->getId();
		$helper->submit_action = 'configuration';
		return $helper->generateForm($fields_form);
	}

	private function generateCredentialsForm()
	{
		$fields_form[0]['form'] = [
			'legend' => [
				'title' => $this->l('Client credentials'),
				'icon' => 'icon-user',
			],
			'input' => [
				[
					'type' => 'text',
					'label' => $this->l('IdentityKey'),
					'name' => self::SIZEID_IDENTITY_KEY,
					'required' => TRUE,
				],
				[
					'type' => 'text',
					'label' => $this->l('API Secure Key'),
					'name' => self::SIZEID_API_SECURE_KEY,
					'required' => TRUE,
				],
			],
			'submit' => [
				'title' => $this->l('Save'),
			],
		];
		$helper = $this->createFormHelper();
		$helper->fields_value[self::SIZEID_IDENTITY_KEY] = $this->getIdentityKey();
		$helper->fields_value[self::SIZEID_API_SECURE_KEY] = $this->getApiSecureKey();
		$helper->submit_action = self::SUBMIT_CREDENTIALS;
		return $helper->generateForm($fields_form);
	}

	private function generateExportForm()
	{
		$fields_form[0]['form'] = [
			'legend' => [
				'title' => $this->l('Export'),
				'icon' => 'icon-file',
			],
			'input' => [
				[
					'type' => 'select',
					'name' => self::SIZEID_EXPORT_FILE,
					'label' => $this->l('Filters'),
					'required' => TRUE,
					'desc' => $this->l('Export products for SizeID size charts matching.'),
					'options' => [
						'query' => [
							[
								'id' => 'all',
								'name' => $this->l('All products'),
							],
						],
						'id' => 'id',
						'name' => 'name',
					],
				],
			],
			'submit' => [
				'title' => $this->l('Export CSV'),
			],
		];
		$helper = $this->createFormHelper();
		$helper->fields_value[self::SIZEID_EXPORT_FILE] = 'all';
		$helper->submit_action = self::SUBMIT_EXPORT;
		return $helper->generateForm($fields_form);
	}

	private function generateImportForm()
	{
		$fields_form[0]['form'] = [
			'legend' => [
				'title' => $this->l('Import'),
				'icon' => 'icon-file',
			],
			'input' => [
				[
					'type' => 'file',
					'label' => $this->l('CSV'),
					'name' => self::SIZEID_IMPORT_FILE,
					// Do not wrap this function, it will break translation export.
					'desc' => $this->l('Import size charts matching. CSV in the same format as export. CSV style: encoding=UTF-8, delimiter=comma, enclosure=double quotes, escape=backslash, newline=LF'),
					'required' => TRUE,
				],
			],
			'submit' => [
				'title' => $this->l('Import CSV'),
			],
		];
		$helper = $this->createFormHelper();
		$helper->submit_action = self::SUBMIT_IMPORT;
		return $helper->generateForm($fields_form);
	}

	private function processImportForm($file)
	{
		if ($file['mime'] !== 'text/csv') {
			throw new SizeIDInvalidInputException('Invalid file format. CSV expected.');
		}
		$csv = $this->readCsv($file['tmp_name']);
		if (count($csv[0]) < 2) {
			throw new SizeIDInvalidInputException('Invalid CSV header length.');
		}
		if ($csv[0][0] !== 'id_product' || $csv[0][1] !== 'size_chart_id') {
			throw new SizeIDInvalidInputException('Invalid header format. Expected header: id_product, size_chart_id');
		}
		unset($csv[0]);
		if (count($csv) < 1) {
			throw new SizeIDInvalidInputException('Empty csv provided.');
		}
		$successCount = 0;
		$errors = [];
		foreach ($csv as $i => $line) {
			$rv = Database::execute(
				"INSERT INTO `:table_name` (`id_product`, `size_chart_id`) values(:id_product, :size_chart_id) ON DUPLICATE KEY UPDATE size_chart_id = :size_chart_id",
				[
					'table_name' => Database::getTableName(),
					'id_product' => $line[0],
					'size_chart_id' => $line[1],
				]
			);
			$rv === FALSE ? $errors[] = sprintf('line_number=%d, line_content="%s"', $i, implode(', ', $line)) : $successCount++;
		}
		return [$successCount, $errors];
	}

	private function readCsv($filename)
	{
		return array_map(
			function ($line) {
				return array_map('trim', str_getcsv($line));
			},
			explode(PHP_EOL, trim(file_get_contents($filename)))
		);
	}

	/**
	 * @return HelperForm
	 */
	private function createFormHelper()
	{
		$default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
		$helper = new HelperForm();
		$helper->module = $this;
		$helper->name_controller = $this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
		// Language
		$helper->default_form_language = $default_lang;
		$helper->allow_employee_form_lang = $default_lang;
		// Title and toolbar
		$helper->title = $this->displayName;
		$helper->show_toolbar = TRUE;        // false -> remove toolbar
		$helper->toolbar_scroll = TRUE;      // yes - > Toolbar is always visible on the top of the screen.
		return $helper;
	}

	private function getProductId()
	{
		return (int)Tools::getValue('id_product');
	}

	private function getIdentityKey()
	{
		return Configuration::get(self::SIZEID_IDENTITY_KEY);
	}

	private function getApiSecureKey()
	{
		return Configuration::get(self::SIZEID_API_SECURE_KEY);
	}

	/**
	 * @return \SizeID\Helpers\Button
	 */
	private function getButton()
	{
		return \SizeID\Helpers\Button::fromTemplate(
			unserialize(Configuration::get(self::SIZEID_BUTTON_TEMPLATE))
		);
	}

	/**
	 * @return \SizeID\Helpers\EshopPlatformHelper
	 */
	private function getSizeIDHelper()
	{
		return $this->createSizeIDHelper($this->getIdentityKey(), $this->getApiSecureKey());
	}

	/**
	 * @param $identityKey
	 * @param $apiSecureKey
	 * @return \SizeID\Helpers\EshopPlatformHelper
	 */
	private function createSizeIDHelper($identityKey, $apiSecureKey)
	{
		$clientApi = new \SizeID\Helpers\ClientApi($identityKey, $apiSecureKey);
		$clientApi->setApiLanguage($this->context->language->iso_code);
		return new \SizeID\Helpers\EshopPlatformHelper($clientApi);
	}

	private function loadSizeChartId()
	{
		$result = Database::getRow(
			"SELECT `size_chart_id` FROM `:table_name` WHERE `id_product` = :id_product",
			[
				'table_name' => Database::getTableName(),
				'id_product' => $this->getProductId(),
			]
		);
		if ($result) {
			return $result['size_chart_id'];
		}
	}
}

class SizeIDInvalidInputException extends \Exception
{

}