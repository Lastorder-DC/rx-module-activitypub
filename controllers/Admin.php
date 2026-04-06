<?php

namespace Rhymix\Modules\Activitypub\Controllers;

use Rhymix\Modules\Activitypub\Models\Config as ConfigModel;
use Rhymix\Modules\Activitypub\Models\Actor as ActorModel;
use BaseObject;
use Context;
use MemberModel;
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

		// Actor 목록에 추가 정보 부여
		if (!empty($actor_list))
		{
			if (!is_array($actor_list))
			{
				$actor_list = [$actor_list];
			}
			foreach ($actor_list as &$actor)
			{
				$actor_type = $actor->actor_type ?? 'board';

				// 삭제된 Actor는 추가 정보 부여 생략
				if (($actor->is_deleted ?? 'N') === 'Y')
				{
					$actor->type_label = '-';
					continue;
				}

				if ($actor_type === 'board' && $actor->module_srl)
				{
					$actor->mid = ModuleModel::getMidByModuleSrl($actor->module_srl);
					$module_info = ModuleModel::getModuleInfoByModuleSrl($actor->module_srl);
					$actor->type_label = $actor->mid ?: ('module_srl:' . $actor->module_srl);
				}
				elseif ($actor_type === 'user' && $actor->member_srl)
				{
					$member_info = MemberModel::getMemberInfoByMemberSrl($actor->member_srl);
					$actor->type_label = $member_info->nick_name ?? ('member_srl:' . $actor->member_srl);

					// 모듈 필터 정보
					$filter_modules = ActorModel::getActorModules($actor->actor_srl);
					$filter_mids = [];
					foreach ($filter_modules as $fm)
					{
						$fmid = ModuleModel::getMidByModuleSrl($fm->module_srl);
						if ($fmid)
						{
							$filter_mids[] = $fmid;
						}
					}
					$actor->filter_mids = $filter_mids;
				}
				else
				{
					$actor->type_label = '-';
				}
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

		// 댓글 전송 여부 설정
		$config->send_comments = ($vars->send_comments === 'Y') ? 'Y' : 'N';

		// Authorized Fetch (Secure Mode) 설정
		$config->authorized_fetch = ($vars->authorized_fetch === 'Y') ? 'Y' : 'N';

		// 설정 저장
		$output = ConfigModel::setConfig($config);
		if (!$output->toBool())
		{
			return $output;
		}

		$this->setMessage('success_registed');
		$this->setRedirectUrl(Context::get('success_return_url'));
	}

	/**
	 * Actor 생성 화면
	 */
	public function dispActivitypubAdminCreateActor()
	{
		Context::set('site_domain', ActorModel::getSiteDomain());
		$this->setTemplateFile('actor_create');
	}

	/**
	 * Actor 생성 처리
	 */
	public function procActivitypubAdminCreateActor()
	{
		$vars = Context::getRequestVars();
		$actor_type = $vars->actor_type ?? '';
		$preferred_username = trim($vars->preferred_username ?? '');
		$display_name = trim($vars->display_name ?? '');
		$summary = trim($vars->summary ?? '');
		$icon_url = trim($vars->icon_url ?? '');
		$hide_followers = ($vars->hide_followers ?? 'N') === 'Y' ? 'Y' : 'N';

		if (!$preferred_username)
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		// preferred_username 유효성: 영문소문자, 숫자, 언더스코어만 허용
		if (!preg_match('/^[a-z0-9_]+$/', $preferred_username))
		{
			return new BaseObject(-1, 'msg_activitypub_invalid_username');
		}

		// icon_url 유효성
		if ($icon_url !== '' && !filter_var($icon_url, FILTER_VALIDATE_URL))
		{
			return new BaseObject(-1, 'msg_activitypub_invalid_icon_url');
		}

		if ($actor_type === 'board')
		{
			$mid = trim($vars->target_mid ?? '');
			if (!$mid)
			{
				return new BaseObject(-1, 'msg_invalid_request');
			}

			$srl_list = ModuleModel::getModuleSrlByMid($mid);
			if (empty($srl_list))
			{
				return new BaseObject(-1, 'msg_activitypub_module_not_found');
			}
			$module_srl = intval($srl_list[0]);

			$output = ActorModel::createBoardActor($module_srl, $preferred_username, $display_name, $summary, $icon_url, $hide_followers);
			if (!$output->toBool())
			{
				return $output;
			}
		}
		elseif ($actor_type === 'user')
		{
			$member_srl = intval($vars->target_member_srl ?? 0);
			if (!$member_srl)
			{
				return new BaseObject(-1, 'msg_invalid_request');
			}

			$member_info = MemberModel::getMemberInfoByMemberSrl($member_srl);
			if (!$member_info || !$member_info->member_srl)
			{
				return new BaseObject(-1, 'msg_activitypub_member_not_found');
			}

			$output = ActorModel::createUserActor($member_srl, $preferred_username, $display_name, $summary, $icon_url, $hide_followers);
			if (!$output->toBool())
			{
				return $output;
			}

			// 모듈 필터 설정
			$filter_module_srls = $this->parseMidsToModuleSrls($vars->filter_mids ?? '');
			if (!empty($filter_module_srls))
			{
				ActorModel::setActorModules($output->data->actor_srl, $filter_module_srls);
			}
		}
		else
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		$this->setMessage('success_registed');
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispActivitypubAdminConfig'));
	}

	/**
	 * Actor 프로필 편집 화면
	 */
	public function dispActivitypubAdminActorEdit()
	{
		$actor_srl = intval(Context::get('actor_srl'));
		if (!$actor_srl)
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		$actor = ActorModel::getActor($actor_srl);
		if (!$actor || ($actor->is_deleted ?? 'N') === 'Y')
		{
			return new BaseObject(-1, 'msg_not_founded');
		}

		$actor_type = $actor->actor_type ?? 'board';
		$default_name = '';
		$default_summary = '';
		$default_icon_url = '';

		if ($actor_type === 'board' && $actor->module_srl)
		{
			$actor->mid = ModuleModel::getMidByModuleSrl($actor->module_srl);
			$module_info = ModuleModel::getModuleInfoByModuleSrl($actor->module_srl);
			if ($module_info)
			{
				$default_name = $module_info->browser_title ?? $actor->mid;
				$default_summary = $module_info->description ?? '';
			}
		}
		elseif ($actor_type === 'user' && $actor->member_srl)
		{
			$member_info = MemberModel::getMemberInfoByMemberSrl($actor->member_srl);
			if ($member_info)
			{
				$default_name = $member_info->nick_name ?? '';
				$default_icon_url = $member_info->profile_image->src ?? '';
			}

			// 모듈 필터 목록
			$filter_modules = ActorModel::getActorModules($actor->actor_srl);
			$filter_mids = [];
			foreach ($filter_modules as $fm)
			{
				$fmid = ModuleModel::getMidByModuleSrl($fm->module_srl);
				if ($fmid)
				{
					$filter_mids[] = $fmid;
				}
			}
			$actor->filter_mids_str = implode(', ', $filter_mids);
		}

		Context::set('actor', $actor);
		Context::set('default_name', $default_name);
		Context::set('default_summary', $default_summary);
		Context::set('default_icon_url', $default_icon_url);
		Context::set('site_domain', ActorModel::getSiteDomain());

		$this->setTemplateFile('actor_edit');
	}

	/**
	 * Actor 프로필 저장 액션
	 */
	public function procActivitypubAdminUpdateActorProfile()
	{
		$vars = Context::getRequestVars();
		$actor_srl = intval($vars->actor_srl ?? 0);
		if (!$actor_srl)
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		$actor = ActorModel::getActor($actor_srl);
		if (!$actor)
		{
			return new BaseObject(-1, 'msg_not_founded');
		}

		$display_name = trim($vars->display_name ?? '');
		$summary = trim($vars->summary ?? '');
		$icon_url = trim($vars->icon_url ?? '');
		$hide_followers = ($vars->hide_followers ?? 'N') === 'Y' ? 'Y' : 'N';

		// icon_url이 입력된 경우 유효한 URL인지 확인
		if ($icon_url !== '' && !filter_var($icon_url, FILTER_VALIDATE_URL))
		{
			return new BaseObject(-1, 'msg_activitypub_invalid_icon_url');
		}

		$output = ActorModel::updateActorProfile($actor_srl, $display_name, $summary, $icon_url, $hide_followers);
		if (!$output->toBool())
		{
			return $output;
		}

		// 유저 타입인 경우 모듈 필터도 업데이트
		if (($actor->actor_type ?? 'board') === 'user')
		{
			$filter_module_srls = $this->parseMidsToModuleSrls($vars->filter_mids ?? '');
			ActorModel::setActorModules($actor_srl, $filter_module_srls);
		}

		$this->setMessage('success_registed');
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispActivitypubAdminConfig'));
	}

	/**
	 * mid 목록 문자열을 module_srl 배열로 변환
	 *
	 * @param string $mids_raw 쉼표/공백 구분 mid 문자열
	 * @return array module_srl 목록
	 */
	protected function parseMidsToModuleSrls($mids_raw)
	{
		$mids_raw = trim($mids_raw);
		if ($mids_raw === '')
		{
			return [];
		}

		$mids = array_map('trim', preg_split('/[\s,]+/', $mids_raw));
		$mids = array_filter($mids, function ($v) { return $v !== ''; });
		$module_srls = [];
		foreach ($mids as $mid)
		{
			$srl_list = ModuleModel::getModuleSrlByMid($mid);
			if (!empty($srl_list))
			{
				$module_srls = array_merge($module_srls, $srl_list);
			}
		}
		return $module_srls;
	}

	/**
	 * Actor 삭제 처리
	 */
	public function procActivitypubAdminDeleteActor()
	{
		$vars = Context::getRequestVars();
		$actor_srl = intval($vars->actor_srl ?? 0);
		if (!$actor_srl)
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		$actor = ActorModel::getActor($actor_srl);
		if (!$actor || ($actor->is_deleted ?? 'N') === 'Y')
		{
			return new BaseObject(-1, 'msg_not_founded');
		}

		$output = ActorModel::deleteActor($actor_srl);
		if (!$output->toBool())
		{
			return $output;
		}

		$this->setMessage('success_deleted');
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispActivitypubAdminConfig'));
	}

	/**
	 * Actor 팔로워 목록 화면
	 */
	public function dispActivitypubAdminActorFollowers()
	{
		$actor_srl = intval(Context::get('actor_srl'));
		if (!$actor_srl)
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		$actor = ActorModel::getActor($actor_srl);
		if (!$actor || ($actor->is_deleted ?? 'N') === 'Y')
		{
			return new BaseObject(-1, 'msg_not_founded');
		}

		$page = intval(Context::get('page')) ?: 1;
		$followers_output = ActorModel::getFollowers($actor_srl, $page);
		$followers = $followers_output->data ?: [];
		if (!empty($followers) && !is_array($followers))
		{
			$followers = [$followers];
		}

		Context::set('actor', $actor);
		Context::set('follower_list', $followers);
		Context::set('page_navigation', $followers_output->page_navigation ?? null);
		Context::set('site_domain', ActorModel::getSiteDomain());

		$this->setTemplateFile('actor_followers');
	}

	/**
	 * 팔로워 삭제 처리
	 */
	public function procActivitypubAdminDeleteFollower()
	{
		$vars = Context::getRequestVars();
		$follower_srl = intval($vars->follower_srl ?? 0);
		$actor_srl = intval($vars->actor_srl ?? 0);
		if (!$follower_srl || !$actor_srl)
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		$output = ActorModel::removeFollowerByFollowerSrl($follower_srl);
		if (!$output->toBool())
		{
			return $output;
		}

		$this->setMessage('success_deleted');
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispActivitypubAdminActorFollowers', 'actor_srl', $actor_srl));
	}
}
