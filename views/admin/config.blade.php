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
		<h2>{{ $lang->cmd_activitypub_module_target }}</h2>

		<div class="x_control-group">
			<label class="x_control-label">{{ $lang->cmd_activitypub_target_mode }}</label>
			<div class="x_controls">
				<label class="x_inline">
					<input type="radio" name="target_mode" value="include" @checked(($config->target_mode ?? 'include') === 'include') /> {{ $lang->cmd_activitypub_mode_include }}
				</label>
				<label class="x_inline">
					<input type="radio" name="target_mode" value="exclude" @checked(($config->target_mode ?? '') === 'exclude') /> {{ $lang->cmd_activitypub_mode_exclude }}
				</label>
				<p class="x_help-block">{{ $lang->cmd_activitypub_target_mode_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="target_mids">{{ $lang->cmd_activitypub_target_mids }}</label>
			<div class="x_controls">
				<textarea name="target_mids" id="target_mids" rows="5" cols="60" placeholder="board1, board2, board3">{{ implode(', ', $config->target_mids ?? []) }}</textarea>
				<p class="x_help-block">{{ $lang->cmd_activitypub_target_mids_desc }}</p>
			</div>
		</div>
	</section>

	<div class="btnArea x_clearfix">
		<button type="submit" class="x_btn x_btn-primary x_pull-right">{{ $lang->cmd_registration }}</button>
	</div>
</form>

@if (!empty($actor_list))
<section class="section" style="margin-top:20px;">
	<h2>{{ $lang->cmd_activitypub_registered_actors }}</h2>
	<table class="x_table">
		<thead>
			<tr>
				<th>{{ $lang->cmd_activitypub_actor_username }}</th>
				<th>{{ $lang->cmd_activitypub_actor_mid }}</th>
				<th>module_srl</th>
				<th>{{ $lang->cmd_activitypub_actor_address }}</th>
				<th>{{ $lang->cmd_activitypub_actor_regdate }}</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($actor_list as $actor)
			<tr>
				<td>{{ $actor->preferred_username }}</td>
				<td>{{ $actor->mid ?? '-' }}</td>
				<td>{{ $actor->module_srl }}</td>
				<td>@{{ $actor->preferred_username }}@{{ $site_domain }}</td>
				<td>{{ $actor->regdate }}</td>
			</tr>
			@endforeach
		</tbody>
	</table>
	<p class="x_help-block">{{ $lang->cmd_activitypub_actor_immutable_notice }}</p>
</section>
@endif
