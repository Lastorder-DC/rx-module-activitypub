<?php

namespace Rhymix\Modules\Activitypub\Controllers;

/**
 * ActivityPub 연동 모듈 - 설치/업데이트 컨트롤러
 *
 * Copyright (c) Lastorder-DC
 * Licensed under GPLv2
 */
class Install extends Base
{
	/**
	 * 모듈 설치 콜백 함수.
	 *
	 * @return object
	 */
	public function moduleInstall()
	{

	}

	/**
	 * 모듈 업데이트 확인 콜백 함수.
	 *
	 * @return bool
	 */
	public function checkUpdate()
	{
		$oDB = \DB::getInstance();
		if (!$oDB->isColumnExists('activitypub_actors', 'display_name'))
		{
			return true;
		}
		return false;
	}

	/**
	 * 모듈 업데이트 콜백 함수.
	 *
	 * @return object
	 */
	public function moduleUpdate()
	{
		$oDB = \DB::getInstance();
		if (!$oDB->isColumnExists('activitypub_actors', 'display_name'))
		{
			$oDB->addColumn('activitypub_actors', 'display_name', 'varchar', 255, null, false, 'preferred_username');
		}
		if (!$oDB->isColumnExists('activitypub_actors', 'summary'))
		{
			$oDB->addColumn('activitypub_actors', 'summary', 'bigtext', null, null, false, 'display_name');
		}
		if (!$oDB->isColumnExists('activitypub_actors', 'icon_url'))
		{
			$oDB->addColumn('activitypub_actors', 'icon_url', 'varchar', 500, null, false, 'summary');
		}
		return new \BaseObject();
	}

	/**
	 * 캐시파일 재생성 콜백 함수.
	 *
	 * @return void
	 */
	public function recompileCache()
	{

	}
}
