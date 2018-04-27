<?php /* Written by Doug Webster in 2011. Determines and displays eligibility for little league pitchers based on league's complex rules. */

// include database connection
$useDB = TRUE;
include_once('config.inc');
include(INCLUDE_DIR . 'functions.php');

$file = fopen(SPRING_BREAK_DATE_FILE, 'r');
// the spring break file should be in the format MM/DD/YYYY
$spring_break_utc = strtotime(fgets($file));
fclose($file);
$sb_index = ($today_utc <= $spring_break_utc) ? 0 : 1; // determines which rules are used

//-----------------------------------------------------------------------------

include(INCLUDE_DIR . 'header.php');
echo '<style>'.NL;
include(INCLUDE_DIR . 'style.css');
echo '</style>'.NL;

echo NL."<h3>Pitch Count Report for week {$last_sunday_display} through {$next_saturday_display}</h3>".NL;

$divisions = get_divisions(); // get all divisions

if (isset($_GET['league'])) {
	$league = addslashes($_GET['league']);
} else {
	echo "<p>Please select a league:</p>".NL;
}

foreach ($divisions as $division_id => $division_name) {

	// this section was added so as to display only one league at a time
	// if a league has not been selected, it will display links only
	// otherwise, if the current division doesn't match the selected league, it will skip to the next
	if (isset($league)) {
		if ($division_id != $league) {
			continue;
		}
	} else {
		echo "<a href=\"pitcher_eligibility.php?league={$division_id}\">{$division_name}</a><br />".NL;
		continue;
	}

	// set rules
	$max_pitches_game = $rules[$division_name]['max_pitches_game'][$sb_index];
	$max_pitches_week = $rules[$division_name]['max_pitches_week'][$sb_index];
	$rdays_pitch_counts = $rules[$division_name]['rdays_pitch_counts'];
	$travel_rules = get_travel_rules($division_id, $next_sunday_sql); // whether or not travel rules apply this week

	echo '<div class="league">'.NL;
	echo "<h2>{$division_name}</h2>".NL;

	$teams = get_teams($division_id); // get all teams in division

	foreach ($teams as $team_id => $team_name) {
	
		echo '<div class="team">'.NL;
		echo "<h3>{$team_name}</h3>".NL;

?>
<table class="players">
	<tr>
		<th class="left c1">Name</th>
		<th class="c2">Eligibility Date</th>
		<th class="c3">Remaining Pitches<br />This Week</th>
		<th class="c4">Notes</th>
	</tr>
<?php

		$players = get_players($team_id); // get all players on team

		foreach ($players as $player_id => $player_name) {

			// initialize some variables for each new player
			$eligibility_date_utc = $last_sunday_utc; // Unix timestamp initialized
			$num_pitches_week = 0; // total number of pitches a player has thrown this week
			$num_remaining_pitches = $max_pitches_week;
			$pc_list = ''; 
			$travel_player = is_travel_player($player_id);
			$eligible_next_sunday = TRUE;
			$pitched_this_week = FALSE; 
			$player_notes = array();
			$pni = 0; // player notes index
			$pc_list = array(); // pitch count list
			$pcli = 0; // pitch count list index

			// making an array here; indexes correspond to the day of the week 0 for Sun - 6 for Sat
			// need to subtract one from the number, because number is lowest for that amount of recovery days
			$max_pitches[3] = $rdays_pitch_counts[3] - 1;
			$max_pitches[4] = $rdays_pitch_counts[2] - 1;
			$max_pitches[5] = $rdays_pitch_counts[1] - 1;
			$max_pitches[6] = $rdays_pitch_counts[0] - 1;
						
			// Rule: this section checks to see if a travel player pitched too much the previous Sunday
			if ($travel_player) {
				$query = "SELECT * FROM `pitch_counts` WHERE `player_id` = {$player_id} AND `date` = '{$previous_sunday_sql}'"; 
				$result = mysql_query($query);
				if (!$result) {
					exit('Error 55: database query error.');
				}
			
				while ($row = mysql_fetch_assoc($result)) {
					// pre-spring break rules could be in effect last week even if not this week
					$sb_index_last_week = ($previous_sunday_utc <= $spring_break_utc) ? 0 : 1; 
					$max_pitches_game_last_week = $rules[$division_name]['max_pitches_game'][$sb_index_last_week];
						
					if ($row['pitch_count'] > $max_pitches_game_last_week) {
						// if a player throws too much in a travel game, they are ineligible for
						// the following week's travel game, through they may be eligible to play
						// rec after Wednesday
						$verb = ($last_sunday_utc == $today_utc) ? 'is' : 'was';
						$player_notes[$pni++] = "Threw {$row['pitch_count']} pitches on {$previous_sunday_display}, more than the limit of {$max_pitches_game_last_week}, and {$verb} therefore ineligible on {$last_sunday_display}.";
						$eligibility_date_utc = strtotime('+1 day', $last_sunday_utc); // not eligible Sunday of present week; eligibility date of Monday
					}
				}
			}
			//-----------------------------------------------------------------
					
			// get this week's pitch counts for player ---------------------------
			
			// we have to go back into last week to check on recovery days
			$date_sql = date(SQL_DATE_FORMAT, strtotime('last Wednesday', $last_sunday_utc));
			
			$query = "SELECT * FROM `pitch_counts` WHERE `player_id` = {$player_id} AND `date` >= '{$date_sql}' ORDER BY `date`"; 
			$result = mysql_query($query);
			if (!$result) {
				exit('Error 54: database query error.');
			}
			
			// -------------------------------------------------------------------
			// This is the main section for calculating pitcher eligibility
			
			// loop for each pitch count for player
			while ($row = mysql_fetch_assoc($result)) {

				// set up some variables
				$game_date_utc = strtotime($row['date']);
				// if the game was on Sunday, assume it is a travel game
				$travel_game = (date('w', $game_date_utc) == 0) ? TRUE : FALSE;
				$game_date_display = date(DATE_DISPLAY_FORMAT, $game_date_utc);
				$num_pitches_game = $row['pitch_count'];
				$pc_list[$pcli]['game_notes'] = array();
				$gni = 0; // game notes index
				$former_eligibility_date_utc = $eligibility_date_utc;
				$recovery_days = 0; // number of days a player must rest since last game

				// this section figures the eligibility date--------------------
				if ($num_pitches_game >= $rdays_pitch_counts[0]) {
					$recovery_days = 1;
					if ($num_pitches_game >= $rdays_pitch_counts[1]) {
						$recovery_days = 2;
						if ($num_pitches_game >= $rdays_pitch_counts[2]) {
							$recovery_days = 3;
							if ($num_pitches_game >= $rdays_pitch_counts[3]) {
								$recovery_days = 4;
							}
						}
					}
					// eligibility date will be the first day after the date of the game plus recovery days
					// or in other words, the date of the game plus recovery days plus 1
					$add_days = $recovery_days + 1;
					$eligibility_date_utc = strtotime("+{$add_days} days", $game_date_utc);
				}
					
				if ($former_eligibility_date_utc > $eligibility_date_utc) {
					$eligibility_date_utc = $former_eligibility_date_utc;
				}
				//-----------------------------------------------------------------
				
				// if game within this week ---------------------------------------
				if ($game_date_utc >= $last_sunday_utc) { 
					
					$pitched_this_week = TRUE; // doing this because there could be query results from last week, even if there aren't any pitch counts this week
					
					$num_pitches_week += $num_pitches_game; // total pitches this week
					$num_remaining_pitches -= $num_pitches_game;
					
					$pc_list[$pcli]['pitch_count'] = $num_pitches_game;
					$pc_list[$pcli]['game_date'] = $game_date_display;
					$pc_list[$pcli]['recovery_days'] = $recovery_days;
					
					// Rule: check to see if player pitched before previous eligibility date
					if ($former_eligibility_date_utc > $game_date_utc) {
						$pc_list[$pcli]['game_notes'][$gni++] = "Pitched before eligibility date of ".date(DATE_DISPLAY_FORMAT, $former_eligibility_date_utc).'.';
					}

					// Rule: check to see if over limit of pitches this game
					if ($num_pitches_game > $max_pitches_game) { 

						if ($travel_game) { 
							// if a player throws too much in a travel game, they are ineligible for
							// the following week's travel game, through they may be eligible to play
							// rec after Wednesday
							$pc_list[$pcli]['game_notes'][$gni++] = "Threw {$num_pitches_game} pitches, more than the limit of {$max_pitches_game}, and is therefore ineligible on {$next_sunday_display}.";
							$eligible_next_sunday = FALSE;
						} else {
							$pc_list[$pcli]['game_notes'][$gni++] = "Threw {$num_pitches_game} pitches, more than the limit of {$max_pitches_game} per game.";
						}
					}

					// Rule: if travel player and travel rules, 
					// check to see if player pitched too much for Sunday eligibility
					if ($travel_player && $travel_rules) {
						$game_day_of_week = date('w', $game_date_utc);
						if ($game_day_of_week >= 3) { // if it's Wednesday or later
							if ($num_pitches_game > $max_pitches[$game_day_of_week]) {
								$pc_list[$pcli]['game_notes'][$gni++] = "Threw {$num_pitches_game} pitches, more than the limit of {$max_pitches[$game_day_of_week]} for Sunday eligibility.";
							}
							
							// here we set the special pitch limits to the lesser of 
							// number of remaining pitches this week or max to still be eligible Sunday
							$dowi = $game_day_of_week + 1; // start checking with tomorrow
							while (($num_remaining_pitches < $max_pitches[$dowi]) && ($dowi <= 6)) {
								$max_pitches[$dowi++] = $num_remaining_pitches;
							}
						}
					}
						
					// Rule: check to see if over max pitches this week
					if ($num_pitches_week >= $max_pitches_week) {
						// if at or over pitch limit for week, set eligibility date to next week
						if ($eligibility_date_utc <= $next_sunday_utc) {
							$eligibility_date_utc = ($eligible_next_sunday) ? $next_sunday_utc : strtotime('+1 day', $next_sunday_utc);
						}
						if ($num_pitches_week > $max_pitches_week) {
							$pc_list[$pcli]['game_notes'][$gni++] = "Threw {$num_pitches_game} pitches, bringing week total to {$num_pitches_week}, more than the limit of {$max_pitches_week}.";
						}
					}
					
					$pcli++;
				} // end game within this week ------------------------------------
					
			} // end loop for each pitch count for player
			
			// Now, display results ----------------------------------------------
			
			$eligibility_date_display = date(DATE_DISPLAY_FORMAT, $eligibility_date_utc); // format for display

			foreach ($max_pitches as $key => $value) {
				// if a player isn't eligible this day, or
				// if special pitch limit for a day doesn't make sense, change to N/A
				if ((($eligibility_date_utc < $next_sunday_utc) 
					&& (date('w', $eligibility_date_utc) > $key))
					|| ($value <= 0))
				{
					$max_pitches[$key] = 'N/A';
				}
			}
			
			if ($eligibility_date_utc <= $today_utc) {
				// if currently eligible, don't display date
				$eligibility_date_display = '';
			} else {
				// if not eligible until next week, don't display remaining pitches for week
				if ($eligibility_date_utc >= $next_sunday_utc) {
					$num_remaining_pitches = 'N/A';
				}
			}
			
			if ($travel_player) {
				// identify travel players
				$player_name .= '*';
			}
			
			echo <<< HTML
	<tr>
		<td class="player_name c1">{$player_name}</td>
		<td class="centered c2">{$eligibility_date_display}</td>
		<td class="centered c3">{$num_remaining_pitches}</td>
		<td class="c4">
HTML;

			if ($travel_player && $travel_rules) {
				// display table with pitch limits for end of week
				echo <<< HTML
			<table class="special_pitch_limits">
				<caption>Special Pitch Limits**</caption>
				<tr>
					<th>Wed.</th>
					<th>Thurs.</th>
					<th>Fri.</th>
					<th>Sat.</th>
				</tr>
				<tr>
					<td class="centered">{$max_pitches[3]}</td>
					<td class="centered">{$max_pitches[4]}</td>
					<td class="centered">{$max_pitches[5]}</td>
					<td class="centered">{$max_pitches[6]}</td>
				</tr>
			</table>
HTML;
			}
						
			$first = TRUE;
			foreach ($player_notes as $player_note) {
				if (!$first) {
					echo '<br />'.NL;
				}
				$first = FALSE;
				echo $player_note;
			}
?>		
		</td>
	</tr>
<?php
			//----------------------------------------
			
			if ($pitched_this_week) {
?>

	<tr>
		<td colspan="4">
			<table class="pitch_counts">
				<tr>
					<th class="left c1">Pitch Date</th>
					<th class="c2">Pitch<br />Count</th>
					<th class="c3">Recovery<br />Days</th>
					<th class="c4"></th>
				</tr>
<?php
				foreach ($pc_list as $pc_entry) {
					echo <<< HTML
				<tr>
					<td class="c1">{$pc_entry['game_date']}</td>
					<td class="centered c2">{$pc_entry['pitch_count']}</td>
					<td class="centered c3">{$pc_entry['recovery_days']}</td>
					<td class="c4">
HTML;
					$first = TRUE;
					foreach ($pc_entry['game_notes'] as $game_note) {
						if (!$first) {
							echo '<br />'.NL;
						}
						$first = FALSE;
						echo $game_note;
					}
?>		
					</td>
				</tr>
<?php
				}
?>
			</table>
		</td>
	</tr>
<?php
			}

		} // end foreach player

		echo '</table><!--players-->'.NL;
		echo '</div><!--team-->'.NL;

	} // end foreach team

	echo '</div><!--league-->'.NL;
?>
<div class="footnotes">
* Designates travel team player.</br>
** These special pitch per game limits apply to travel team players with a travel game this coming Sunday.</br>
</div>
<?php
} // end foreach division/league

include(INCLUDE_DIR . 'footer.php');
