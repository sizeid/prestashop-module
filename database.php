<?php

class Database
{

	public static function query($sql, $variables = [])
	{
		return Db::getInstance()->query(self::replaceVariables($sql, $variables))->fetchAll(PDO::FETCH_ASSOC);
	}

	public static function execute($sql, $variables = [])
	{
		return Db::getInstance()->execute(self::replaceVariables($sql, $variables));
	}

	public static function getRow($sql, $variables = [])
	{
		return Db::getInstance()->getRow(self::replaceVariables($sql, $variables));
	}

	public static function replaceVariables($sql, $params)
	{
		$db = Db::getInstance();
		foreach ($params as $name => $value) {
			$sql = str_replace(":$name", $db->escape($value, TRUE), $sql);
		}
		return $sql;
	}

	public static function install()
	{
		return self::executeSql(
			'
			CREATE TABLE `' . self::getTableName() . '`
			(
				`id_product`  INT UNSIGNED,
				`size_chart_id` INT UNSIGNED NOT NULL,
				PRIMARY KEY (`id_product`),
				FOREIGN KEY (`id_product`) REFERENCES `' . self::prefixTable('product') . '` (`id_product`)
			) ENGINE=' . _MYSQL_ENGINE_ . ' CHARSET=utf8;
			'
		);
	}

	public static function getTableName()
	{
		return self::prefixTable('product_sizeid');
	}

	public static function prefixTable($name)
	{
		return _DB_PREFIX_ . $name;
	}

	public static function uninstall()
	{
		Configuration::deleteByName(SizeID::SIZEID_IDENTITY_KEY);
		Configuration::deleteByName(SizeID::SIZEID_API_SECURE_KEY);
		Configuration::deleteByName(SizeID::SIZEID_BUTTON_TEMPLATE);
		return self::executeSql('DROP TABLE `' . self::getTableName() . '`');
	}

	private static function executeSql($sql)
	{
		return Db::getInstance()->execute($sql);
	}
}