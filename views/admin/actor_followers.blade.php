<div class="x_page-header">
	<h1>{{ $lang->cmd_activitypub }}</h1>
</div>

<ul class="x_nav x_nav-tabs">
	<li>
		<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminConfig'])">{{ $lang->cmd_activitypub_general_config }}</a>
	</li>
</ul>

<div class="x_page-header" style="margin-top:10px;">
	<h2>{{ $lang->cmd_activitypub_actor_followers }} — {{ '@' . $actor->preferred_username . '@' . $site_domain }}</h2>
</div>

@if (!empty($follower_list))
<table class="x_table">
	<thead>
		<tr>
			<th>{{ $lang->cmd_activitypub_follower_actor_url }}</th>
			<th>{{ $lang->cmd_activitypub_follower_inbox_url }}</th>
			<th>{{ $lang->cmd_activitypub_follower_shared_inbox_url }}</th>
			<th>{{ $lang->cmd_activitypub_follower_regdate }}</th>
			<th></th>
		</tr>
	</thead>
	<tbody>
		@foreach ($follower_list as $follower)
		<tr>
			<td style="word-break:break-all;">{{ $follower->follower_actor_url }}</td>
			<td style="word-break:break-all;">{{ $follower->follower_inbox_url }}</td>
			<td style="word-break:break-all;">{{ $follower->follower_shared_inbox_url ?? '-' }}</td>
			<td>{{ zdate($follower->regdate, 'Y-m-d H:i:s') }}</td>
			<td>
				<form action="./" method="post" style="display:inline;" onsubmit="return confirm('{{ $lang->cmd_activitypub_confirm_delete_follower }}');">
					<input type="hidden" name="module" value="activitypub" />
					<input type="hidden" name="act" value="procActivitypubAdminDeleteFollower" />
					<input type="hidden" name="follower_srl" value="{{ $follower->follower_srl }}" />
					<input type="hidden" name="actor_srl" value="{{ $actor->actor_srl }}" />
					<button type="submit" class="x_btn x_btn-small x_btn-danger">{{ $lang->cmd_delete }}</button>
				</form>
			</td>
		</tr>
		@endforeach
	</tbody>
</table>

@if ($page_navigation)
<div class="x_pagination">
	@if ($page_navigation->cur_page > 1)
		<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminActorFollowers', 'actor_srl' => $actor->actor_srl, 'page' => $page_navigation->cur_page - 1])" class="x_btn x_btn-small">&laquo; {{ $lang->cmd_prev }}</a>
	@endif
	<span>{{ $page_navigation->cur_page }} / {{ $page_navigation->last_page }}</span>
	@if ($page_navigation->cur_page < $page_navigation->last_page)
		<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminActorFollowers', 'actor_srl' => $actor->actor_srl, 'page' => $page_navigation->cur_page + 1])" class="x_btn x_btn-small">{{ $lang->cmd_next }} &raquo;</a>
	@endif
</div>
@endif

@else
<p class="x_text-muted">{{ $lang->cmd_activitypub_no_followers }}</p>
@endif

<div class="btnArea x_clearfix" style="margin-top:20px;">
	<a href="@url(['module' => 'admin', 'act' => 'dispActivitypubAdminConfig'])" class="x_btn x_pull-left">{{ $lang->cmd_back }}</a>
</div>
