<?php
// cause fuck namespaces!!
require_once __DIR__ . '/bootstrap.php';

class SizeID extends Module
{

	const SIZEID_IDENTITY_KEY = 'SIZEID_IDENTITY_KEY';
	const SIZEID_API_SECURE_KEY = 'SIZEID_API_SECURE_KEY';
	const SIZEID_BUTTON_TEMPLATE = 'SIZEID_BUTTON_TEMPLATE';
	const SUBMIT_CREDENTIALS = 'credentials';
	const SUBMIT_CONFIGURATION = 'configuration';

	public function __construct()
	{
		$this->name = 'sizeid';
		$this->tab = 'front_office_features';
		$this->version = trim(@file_get_contents(__DIR__ . '/build-version'));
		$this->author = 'Jakub Filla';
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
			$this->context->controller->addJS($this->_path.'views/js/button.js');
			$this->context->controller->addCSS($this->_path.'views/css/button.css');
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
		$html .= $this->generateCredentialsForm();
		if ($this->getSizeIDHelper()->credentialsAreValid()) {
			$html .= $this->generateConfigurationForm();
		}
		return $html;
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