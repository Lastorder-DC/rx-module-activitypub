<?php

namespace Rhymix\Modules\Activitypub\Models;

use ModuleController;
use ModuleModel;

/**
 * ActivityPub 연동 모듈 - 설정 모델
 *
 * Copyright (c) Lastorder-DC
 * Licensed under GPLv2
 */
class Config
{
	/**
	 * 모듈 설정 캐시를 위한 변수.
	 */
	protected static $_cache = null;

	/**
	 * 모듈 설정을 가져오는 함수.
	 *
	 * @return object
	 */
	public static function getConfig()
	{
		if (self::$_cache === null)
		{
			self::$_cache = ModuleModel::getModuleConfig('activitypub') ?: new \stdClass;
		}
		return self::$_cache;
	}

	/**
	 * 모듈 설정을 저장하는 함수.
	 *
	 * @param object $config
	 * @return object
	 */
	public static function setConfig($config)
	{
		$oModuleController = ModuleController::getInstance();
		$result = $oModuleController->insertModuleConfig('activitypub', $config);
		if ($result->toBool())
		{
			self::$_cache = $config;
		}
		return $result;
	}
}
