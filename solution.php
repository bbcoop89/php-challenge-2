<?php
// Brittany Reves <Brittany.Reves@icloud.com>

function parse_request($request, $secret)
{
   //"authenticate" request
   if($secret !== API_SECRET) {
     return false;
   }

   //reverse hashing
   $data = strtr($request, '-_', '+/');
   $parts = explode('.', $data);
   $signature = base64_decode($parts[0]);

   /*
   * signature will be false if request is shortened
   * due to failure to decode
   */
   if(!$signature) {
     return false;
   }

   $payload = base64_decode($parts[1]);
   $payloadObj = json_decode($payload);

   /*
   * payload object will be null if request is reversed
   * due to failure to decode
   */
   if(!is_object($payloadObj)) {
     return false;
   }

   //returns an associative array
   return get_object_vars($payloadObj);
}

function dates_with_at_least_n_scores($pdo, $n)
{
  $score_count = (int)$n;

  //counting the rows that exist per date group
  $sql =
      "SELECT
        date
      FROM
        scores
      GROUP BY
        date
      HAVING
        COUNT(*) >= ?
      ORDER BY
        date DESC";

  $sth = $pdo->prepare($sql);
  $sth->bindValue(1, $score_count, PDO::PARAM_INT);
  $sth->execute();

  //returns an array of dates
  return $sth->fetchAll(PDO::FETCH_COLUMN);
}

function users_with_top_score_on_date($pdo, $date)
{
 /*
 * WHERE filters out results on a particular date
 * and then finds the users that have the top score
 * on that date
 */
  $sql =
    "SELECT
      user_id
    FROM
      scores
    WHERE date = :dateNum
    AND score = (
      SELECT MAX(score) FROM scores WHERE date = :dateNum
    )";

  $sth = $pdo->prepare($sql);
  $sth->bindParam(":dateNum", $date);
  $sth->execute();

  //returns an array of user ids
  return $sth->fetchAll(PDO::FETCH_COLUMN);
}

function dates_when_user_was_in_top_n($pdo, $user_id, $n)
{
  /*
  * sqlite prevents using any kind
  * of dense row rank, partitioning, or use of TOP.
  * Using simple query and executing
  * TOP N, partitioning and ranking algorithm in code.
  */
  $sql = "SELECT
            user_id,
            score,
            date
         FROM
            scores
        ORDER BY
            date DESC,
            score DESC";

  $sth = $pdo->prepare($sql);
  $sth->execute();

  $result = $sth->fetchAll(PDO::FETCH_ASSOC);

  //group row results by date
  $grouped_results = group_result_rows_by_date($result);

  //get the dates where user was in TOP n
  $dates = get_dates_when_user_was_in_top_n($grouped_results, $user_id, $n);

  //return an array of dates
  return $dates;
}

/**
* Iterates through each row grouped by date
* and returns dates where user_id is equal to
* user requested and where his/her score is
* in the top n of scores on the date (based on the indices
* in the array since it is ordered by date and
* score).  Handling use case where the user's
* score has equal ranking to another user's score
* in the TOP n with logical OR - user is within
* top n or shares top n ranking.
*/
function get_dates_when_user_was_in_top_n($grouped_results, $user_id, $n)
{
  $dates = [];

  foreach($grouped_results as $date => $rows) {
    foreach($rows as $index => $row) {
      if(
        user_ids_are_equal($row['user_id'], $user_id)
        && (
            score_is_within_top_n($index, $n)
            || (score_shares_top_n_rank($row['score'], $rows[$index-1]['score']))
          )
      ) {
        $dates[] = $row['date'];
      }
    }
  }

  return $dates;
}

/**
* Groups SQL results by date
*/
function group_result_rows_by_date($result)
{
  $data = [];

  foreach($result as $row) {
    $data[$row['date']][] = $row;
  }

  return $data;
}

/**
* Determines if the user_id of the result row
* is equal to the user_id requested
*/
function user_ids_are_equal($row_user_id, $requested_user_id)
{
  return (int)$row_user_id === (int)$requested_user_id;
}

/**
* Determines if the row is one in which the score
* is in the TOP n on a date.
* NOTE: The score_index has to be incremented by
* one since it is the array index of the row grouped
* by date and array indices start at 0.
*/
function score_is_within_top_n($score_index, $n)
{
  return ($score_index+1) <= (int)$n;
}

/**
* Determines if the user shares a score in the TOP n
* on a date with the previously ranked user, meaning their ranks
* have equal weight.
*/
function score_shares_top_n_rank($current_row_score, $prev_row_score)
{
  return $current_row_score === $prev_row_score;
}
