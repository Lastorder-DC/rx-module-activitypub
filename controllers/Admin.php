<?php

namespace Rhymix\Modules\Activitypub\Controllers;

use Rhymix\Modules\Activitypub\Models\Config as ConfigModel;
use Rhymix\Modules\Activitypub\Models\Actor as ActorModel;
use BaseObject;
use Context;
use ModuleModel;

/**
 * ActivityPub 연동 모듈 - 관리자 컨트롤러
 *
 * Copyright (c) Lastorder-DC
 * Licensed under GPLv2
 */
class Admin extends Base
{
	/**
	 * 초기화
	 */
	public function init()
	{
		$this->setTemplatePath($this->module_path . 'views/admin/');
	}

	/**
	 * 관리자 설정 화면
	 */
	public function dispActivitypubAdminConfig()
	{
		$config = ConfigModel::getConfig();
		Context::set('config', $config);

		// 현재 등록된 Actor 목록
		$actor_list_output = ActorModel::getActorList();
		$actor_list = $actor_list_output->data ?: [];

		// Actor 목록에 mid 정보 추가
		if (!empty($actor_list))
		{
			if (!is_array($actor_list))
			{
				$actor_list = [$actor_list];
			}
			foreach ($actor_list as &$actor)
			{
				$actor->mid = ModuleModel::getMidByModuleSrl($actor->module_srl);
			}
			unset($actor);
		}

		Context::set('actor_list', $actor_list);
		Context::set('site_domain', ActorModel::getSiteDomain());

		$this->setTemplateFile('config');
	}

	/**
	 * 관리자 설정 저장 액션
	 */
	public function procActivitypubAdminInsertConfig()
	{
		$config = ConfigModel::getConfig();
		$vars = Context::getRequestVars();

		// 포함/제외 모드 설정
		if (in_array($vars->target_mode, ['include', 'exclude']))
		{
			$config->target_mode = $vars->target_mode;
		}
		else
		{
			$config->target_mode = 'include';
		}

		// 대상 mid 목록 설정
		$target_mids_raw = trim($vars->target_mids ?? '');
		if ($target_mids_raw !== '')
		{
			$target_mids = array_map('trim', preg_split('/[\s,]+/', $target_mids_raw));
			$target_mids = array_filter($target_mids, function ($v) { return $v !== ''; });
			$target_mids = array_values(array_unique($target_mids));
		}
		else
		{
			$target_mids = [];
		}
		$config->target_mids = $target_mids;

		// 댓글 전송 여부 설정
		$config->send_comments = ($vars->send_comments === 'Y') ? 'Y' : 'N';

		// 설정에 포함된 mid에 대해 Actor 자동 생성
		if ($config->target_mode === 'include')
		{
			foreach ($target_mids as $mid)
			{
				$srl_list = ModuleModel::getModuleSrlByMid($mid);
				if (!empty($srl_list))
				{
					foreach ($srl_list as $module_srl)
					{
						$existing = ActorModel::getActorByModuleSrl($module_srl);
						if (!$existing)
						{
							ActorModel::createActor($module_srl, $mid);
						}
					}
				}
			}
		}

		// 설정 저장
		$output = ConfigModel::setConfig($config);
		if (!$output->toBool())
		{
			return $output;
		}

		$this->setMessage('success_registed');
		$this->setRedirectUrl(Context::get('success_return_url'));
	}
}
