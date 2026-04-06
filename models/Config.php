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
	 * 기본 설정값
	 */
	public const DEFAULT_CONTENT_MAX_LENGTH = 500;
	public const DEFAULT_OUTBOX_PAGE_SIZE = 20;

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
	 * 컨텐츠 최대 길이 설정값 반환
	 *
	 * @return int
	 */
	public static function getContentMaxLength()
	{
		$config = self::getConfig();
		return intval($config->content_max_length ?? self::DEFAULT_CONTENT_MAX_LENGTH) ?: self::DEFAULT_CONTENT_MAX_LENGTH;
	}

	/**
	 * Outbox 페이지당 항목 수 설정값 반환
	 *
	 * @return int
	 */
	public static function getOutboxPageSize()
	{
		$config = self::getConfig();
		return intval($config->outbox_page_size ?? self::DEFAULT_OUTBOX_PAGE_SIZE) ?: self::DEFAULT_OUTBOX_PAGE_SIZE;
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
