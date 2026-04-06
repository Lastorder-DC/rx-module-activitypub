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
		return false;
	}

	/**
	 * 모듈 업데이트 콜백 함수.
	 *
	 * @return object
	 */
	public function moduleUpdate()
	{

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
