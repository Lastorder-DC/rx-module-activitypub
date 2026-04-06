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

		// is_deleted 컬럼이 없으면 업데이트 필요
		if (!$oDB->isColumnExists('activitypub_actors', 'is_deleted'))
		{
			return true;
		}

		// hide_followers 컬럼이 없으면 업데이트 필요
		if (!$oDB->isColumnExists('activitypub_actors', 'hide_followers'))
		{
			return true;
		}

		// discoverable 컬럼이 없으면 업데이트 필요
		if (!$oDB->isColumnExists('activitypub_actors', 'discoverable'))
		{
			return true;
		}

		// indexable 컬럼이 없으면 업데이트 필요
		if (!$oDB->isColumnExists('activitypub_actors', 'indexable'))
		{
			return true;
		}

		// visibility 컬럼이 없으면 업데이트 필요
		if (!$oDB->isColumnExists('activitypub_actors', 'visibility'))
		{
			return true;
		}

		// quote_policy 컬럼이 없으면 업데이트 필요
		if (!$oDB->isColumnExists('activitypub_actors', 'quote_policy'))
		{
			return true;
		}

		// category_filter_mode 컬럼이 없으면 업데이트 필요
		if (!$oDB->isColumnExists('activitypub_actors', 'category_filter_mode'))
		{
			return true;
		}

		// attach_thumbnail 컬럼이 없으면 업데이트 필요
		if (!$oDB->isColumnExists('activitypub_actors', 'attach_thumbnail'))
		{
			return true;
		}

		// sensitive_mode 컬럼이 없으면 업데이트 필요
		if (!$oDB->isColumnExists('activitypub_actors', 'sensitive_mode'))
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

		// is_deleted 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'is_deleted'))
		{
			$oDB->addColumn('activitypub_actors', 'is_deleted', 'char', 1, 'N', true, 'private_key');
			$oDB->addIndex('activitypub_actors', 'idx_is_deleted', ['is_deleted']);
		}

		// hide_followers 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'hide_followers'))
		{
			$oDB->addColumn('activitypub_actors', 'hide_followers', 'char', 1, 'N', true, 'private_key');
		}

		// discoverable 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'discoverable'))
		{
			$oDB->addColumn('activitypub_actors', 'discoverable', 'char', 1, 'Y', true, 'hide_followers');
		}

		// indexable 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'indexable'))
		{
			$oDB->addColumn('activitypub_actors', 'indexable', 'char', 1, 'N', true, 'discoverable');
		}

		// visibility 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'visibility'))
		{
			$oDB->addColumn('activitypub_actors', 'visibility', 'varchar', 10, 'unlisted', true, 'indexable');
		}

		// quote_policy 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'quote_policy'))
		{
			$oDB->addColumn('activitypub_actors', 'quote_policy', 'varchar', 20, 'nobody', true, 'visibility');
		}

		// category_filter_mode 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'category_filter_mode'))
		{
			$oDB->addColumn('activitypub_actors', 'category_filter_mode', 'varchar', 10, 'off', true, 'quote_policy');
		}

		// category_filter_srls 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'category_filter_srls'))
		{
			$oDB->addColumn('activitypub_actors', 'category_filter_srls', 'varchar', 500, null, false, 'category_filter_mode');
		}

		// attach_thumbnail 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'attach_thumbnail'))
		{
			$oDB->addColumn('activitypub_actors', 'attach_thumbnail', 'char', 1, 'N', true, 'category_filter_srls');
		}

		// sensitive_mode 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'sensitive_mode'))
		{
			$oDB->addColumn('activitypub_actors', 'sensitive_mode', 'varchar', 20, 'off', true, 'attach_thumbnail');
		}

		// sensitive_category_srls 컬럼 추가
		if (!$oDB->isColumnExists('activitypub_actors', 'sensitive_category_srls'))
		{
			$oDB->addColumn('activitypub_actors', 'sensitive_category_srls', 'varchar', 500, null, false, 'sensitive_mode');
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
