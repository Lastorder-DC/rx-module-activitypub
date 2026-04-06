<div class="x_page-header">
	<h1>{{ $lang->cmd_activitypub }}</h1>
</div>

<ul class="x_nav x_nav-tabs">
	<li>
		<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminConfig'])">{{ $lang->cmd_activitypub_general_config }}</a>
	</li>
</ul>

<div class="x_page-header" style="margin-top:10px;">
	<h2>{{ $lang->cmd_activitypub_actor_edit }} — @{{ $actor->preferred_username }}@{{ $site_domain }}</h2>
</div>

<form class="x_form-horizontal" action="./" method="post">
	<input type="hidden" name="module" value="activitypub" />
	<input type="hidden" name="act" value="procActivitypubAdminUpdateActorProfile" />
	<input type="hidden" name="actor_srl" value="{{ $actor->actor_srl }}" />
	<input type="hidden" name="xe_validator_id" value="modules/activitypub/views/admin/actor_edit/1" />

	@if (!empty($XE_VALIDATOR_MESSAGE) && $XE_VALIDATOR_ID == 'modules/activitypub/views/admin/actor_edit/1')
		<div class="message {{ $XE_VALIDATOR_MESSAGE_TYPE }}">
			<p>{{ $XE_VALIDATOR_MESSAGE }}</p>
		</div>
	@endif

	<section class="section">
		<div class="x_control-group">
			<label class="x_control-label" for="display_name">{{ $lang->cmd_activitypub_display_name }}</label>
			<div class="x_controls">
				<input type="text" name="display_name" id="display_name" value="{{ $actor->display_name ?? '' }}" placeholder="{{ $default_name }}" style="width:300px;" />
				<p class="x_help-block">{{ $lang->cmd_activitypub_display_name_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="actor_summary">{{ $lang->cmd_activitypub_actor_summary }}</label>
			<div class="x_controls">
				<textarea name="summary" id="actor_summary" rows="5" cols="60" placeholder="{{ $default_summary }}">{{ $actor->summary ?? '' }}</textarea>
				<p class="x_help-block">{{ $lang->cmd_activitypub_actor_summary_desc }}</p>
			</div>
		</div>

		<div class="x_control-group">
			<label class="x_control-label" for="icon_url">{{ $lang->cmd_activitypub_icon_url }}</label>
			<div class="x_controls">
				<input type="url" name="icon_url" id="icon_url" value="{{ $actor->icon_url ?? '' }}" placeholder="https://example.com/image.png" style="width:400px;" />
				<p class="x_help-block">{{ $lang->cmd_activitypub_icon_url_desc }}</p>
			</div>
		</div>
	</section>

	<div class="btnArea x_clearfix">
		<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminConfig'])" class="x_btn x_pull-left">{{ $lang->cmd_back }}</a>
		<button type="submit" class="x_btn x_btn-primary x_pull-right">{{ $lang->cmd_registration }}</button>
	</div>
</form>
