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

		// actor_type 컬럼이 없으면 업데이트 필요
		if (!$oDB->isColumnExists('activitypub_actors', 'actor_type'))
		{
			return true;
		}

		// display_name 컬럼이 없으면 업데이트 필요
		if (!$oDB->isColumnExists('activitypub_actors', 'display_name'))
		{
			return true;
		}

		// actor_modules 테이블이 없으면 업데이트 필요
		if (!$oDB->isTableExists('activitypub_actor_modules'))
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

		// actor_type 컬럼 추가 (기존 Actor는 board 타입으로)
		if (!$oDB->isColumnExists('activitypub_actors', 'actor_type'))
		{
			$oDB->addColumn('activitypub_actors', 'actor_type', 'varchar', 10, 'board', true, 'actor_srl');
			$oDB->addIndex('activitypub_actors', 'idx_actor_type', ['actor_type']);
		}

		// member_srl 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'member_srl'))
		{
			$oDB->addColumn('activitypub_actors', 'member_srl', 'number', 11, null, false, 'actor_type');
			$oDB->addIndex('activitypub_actors', 'idx_member_srl', ['member_srl']);
		}

		// display_name 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'display_name'))
		{
			$oDB->addColumn('activitypub_actors', 'display_name', 'varchar', 255, null, false, 'preferred_username');
		}

		// summary 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'summary'))
		{
			$oDB->addColumn('activitypub_actors', 'summary', 'bigtext', null, null, false, 'display_name');
		}

		// icon_url 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'icon_url'))
		{
			$oDB->addColumn('activitypub_actors', 'icon_url', 'varchar', 500, null, false, 'summary');
		}

		// actor_modules 테이블 생성
		if (!$oDB->isTableExists('activitypub_actor_modules'))
		{
			$oDB->createTableByXmlFile($this->module_path . 'schemas/activitypub_actor_modules.xml');
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
