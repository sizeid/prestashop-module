<?php

class Module
{

	public
		$name,
		$tab,
		$version,
		$author,
		$need_instance,
		$ps_versions_compliancy,
		$bootstrap,
		$displayName,
		$description,
		$confirmUninstall,
		$warning;

	public function __construct()
	{
	}

	protected function l()
	{
	}

	public function install()
	{
		return TRUE;
	}

	public function uninstall()
	{
		return TRUE;
	}

	public function registerHook()
	{
		return TRUE;
	}
}