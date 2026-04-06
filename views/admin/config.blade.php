<div class="x_page-header">
	<h1>{{ $lang->cmd_activitypub }}</h1>
</div>

<ul class="x_nav x_nav-tabs">
	<li @class(['x_active' => $act === 'dispActivitypubAdminConfig'])>
		<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminConfig'])">{{ $lang->cmd_activitypub_general_config }}</a>
	</li>
</ul>

<form class="x_form-horizontal" action="./" method="post" id="activitypub_config">
	<input type="hidden" name="module" value="activitypub" />
	<input type="hidden" name="act" value="procActivitypubAdminInsertConfig" />
	<input type="hidden" name="success_return_url" value="{{ getRequestUriByServerEnviroment() }}" />
	<input type="hidden" name="xe_validator_id" value="modules/activitypub/views/admin/config/1" />

	@if (!empty($XE_VALIDATOR_MESSAGE) && $XE_VALIDATOR_ID == 'modules/activitypub/views/admin/config/1')
		<div class="message {{ $XE_VALIDATOR_MESSAGE_TYPE }}">
			<p>{{ $XE_VALIDATOR_MESSAGE }}</p>
		</div>
	@endif

	<section class="section">
		<h2>{{ $lang->cmd_activitypub_general_config }}</h2>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_module_enabled }}</label>
			<div class="x_controls">
				<label class="x_inline">
					<input type="radio" name="module_enabled" value="Y" @checked(($config->module_enabled ?? 'Y') === 'Y') /> {{ $lang->cmd_yes }}
				</label>
				<label class="x_inline">
					<input type="radio" name="module_enabled" value="N" @checked(($config->module_enabled ?? 'Y') === 'N') /> {{ $lang->cmd_no }}
				</label>
				<p class="x_help-block">{{ $lang->cmd_activitypub_module_enabled_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_debug_enabled }}</label>
			<div class="x_controls">
				<label class="x_inline">
					<input type="radio" name="debug_enabled" value="Y" @checked(($config->debug_enabled ?? 'N') === 'Y') /> {{ $lang->cmd_yes }}
				</label>
				<label class="x_inline">
					<input type="radio" name="debug_enabled" value="N" @checked(($config->debug_enabled ?? 'N') === 'N') /> {{ $lang->cmd_no }}
				</label>
				<p class="x_help-block">{{ $lang->cmd_activitypub_debug_enabled_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_send_comments }}</label>
			<div class="x_controls">
				<label class="x_inline">
					<input type="radio" name="send_comments" value="Y" @checked(($config->send_comments ?? 'N') === 'Y') /> {{ $lang->cmd_yes }}
				</label>
				<label class="x_inline">
					<input type="radio" name="send_comments" value="N" @checked(($config->send_comments ?? 'N') === 'N') /> {{ $lang->cmd_no }}
				</label>
				<p class="x_help-block">{{ $lang->cmd_activitypub_send_comments_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_authorized_fetch }}</label>
			<div class="x_controls">
				<label class="x_inline">
					<input type="radio" name="authorized_fetch" value="Y" @checked(($config->authorized_fetch ?? 'N') === 'Y') /> {{ $lang->cmd_yes }}
				</label>
				<label class="x_inline">
					<input type="radio" name="authorized_fetch" value="N" @checked(($config->authorized_fetch ?? 'N') === 'N') /> {{ $lang->cmd_no }}
				</label>
				<p class="x_help-block">{{ $lang->cmd_activitypub_authorized_fetch_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_content_max_length }}</label>
			<div class="x_controls">
				<input type="number" name="content_max_length" value="{{ $config->content_max_length ?? 500 }}" min="100" max="5000" class="x_input-small" />
				<p class="x_help-block">{{ $lang->cmd_activitypub_content_max_length_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_include_content }}</label>
			<div class="x_controls">
				<label class="x_inline">
					<input type="radio" name="include_content" value="N" @checked(($config->include_content ?? 'N') === 'N') /> {{ $lang->cmd_activitypub_include_content_off }}
				</label>
				<label class="x_inline">
					<input type="radio" name="include_content" value="Y" @checked(($config->include_content ?? 'N') === 'Y') /> {{ $lang->cmd_activitypub_include_content_on }}
				</label>
				<label class="x_inline">
					<input type="radio" name="include_content" value="cw" @checked(($config->include_content ?? 'N') === 'cw') /> {{ $lang->cmd_activitypub_include_content_cw }}
				</label>
				<p class="x_help-block">{{ $lang->cmd_activitypub_include_content_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_outbox_page_size }}</label>
			<div class="x_controls">
				<input type="number" name="outbox_page_size" value="{{ $config->outbox_page_size ?? 20 }}" min="5" max="100" class="x_input-small" />
				<p class="x_help-block">{{ $lang->cmd_activitypub_outbox_page_size_desc }}</p>
			</div>
		</div>
	</section>

	<div class="btnArea x_clearfix">
		<button type="submit" class="x_btn x_btn-primary x_pull-right">{{ $lang->cmd_registration }}</button>
	</div>
</form>

<section class="section" style="margin-top:20px;">
	<h2>{{ $lang->cmd_activitypub_registered_actors }}</h2>

	<div style="margin-bottom:10px;">
		<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminCreateActor'])" class="x_btn x_btn-success">{{ $lang->cmd_activitypub_create_actor }}</a>
	</div>

	@if (!empty($actor_list))
	<table class="x_table">
		<thead>
			<tr>
				<th>{{ $lang->cmd_activitypub_actor_type }}</th>
				<th>{{ $lang->cmd_activitypub_actor_username }}</th>
				<th>{{ $lang->cmd_activitypub_actor_target }}</th>
				<th>{{ $lang->cmd_activitypub_actor_address }}</th>
				<th>{{ $lang->cmd_activitypub_actor_regdate }}</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			@foreach ($actor_list as $actor)
			<tr @if (($actor->is_deleted ?? 'N') === 'Y') class="x_muted" style="opacity:0.5;" @endif>
				<td>
					@if (($actor->is_deleted ?? 'N') === 'Y')
						<span class="x_text-muted">{{ $lang->cmd_activitypub_deleted }}</span>
					@elseif (($actor->actor_type ?? 'board') === 'board')
						{{ $lang->cmd_activitypub_type_board }}
					@else
						{{ $lang->cmd_activitypub_type_user }}
					@endif
				</td>
				<td>{{ $actor->preferred_username }}</td>
				<td>
					@if (($actor->is_deleted ?? 'N') === 'Y')
						<span class="x_text-muted">-</span>
					@else
						{{ $actor->type_label ?? '-' }}
						@if (($actor->actor_type ?? 'board') === 'user' && !empty($actor->filter_mids))
							<br /><small class="x_text-muted">{{ $lang->cmd_activitypub_filter }}: {{ implode(', ', $actor->filter_mids) }}</small>
						@endif
					@endif
				</td>
				<td>{{ '@' . $actor->preferred_username . '@' . $site_domain }}</td>
				<td>{{ zdate($actor->regdate, 'Y-m-d H:i:s') }}</td>
				<td>
					@if (($actor->is_deleted ?? 'N') !== 'Y')
					<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminActorFollowers', 'actor_srl' => $actor->actor_srl])" class="x_btn x_btn-small">{{ $lang->cmd_activitypub_actor_followers }}</a>
					<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminActorEdit', 'actor_srl' => $actor->actor_srl])" class="x_btn x_btn-small">{{ $lang->cmd_activitypub_actor_edit }}</a>
					<form action="./" method="post" style="display:inline;" onsubmit="return confirm('{{ $lang->confirm_delete }}');">
						<input type="hidden" name="module" value="activitypub" />
						<input type="hidden" name="act" value="procActivitypubAdminDeleteActor" />
						<input type="hidden" name="actor_srl" value="{{ $actor->actor_srl }}" />
						<button type="submit" class="x_btn x_btn-small x_btn-danger">{{ $lang->cmd_delete }}</button>
					</form>
					@endif
				</td>
			</tr>
			@endforeach
		</tbody>
	</table>
	@else
	<p class="x_text-muted">{{ $lang->cmd_activitypub_no_actors }}</p>
	@endif

	<p class="x_help-block">{{ $lang->cmd_activitypub_actor_immutable_notice }}</p>
</section>
