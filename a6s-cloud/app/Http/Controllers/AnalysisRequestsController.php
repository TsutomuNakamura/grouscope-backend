<?php

namespace App\Http\Controllers;

use \DateTime;
use \DateTimeZone;
use App\AnalysisResults;
use App\Tweets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Abraham\TwitterOAuth\TwitterOAuth;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use AnalysisRequestService;

class AnalysisRequestsController extends Controller
{
    public function create(Request $request)
    {
        $request->validate([
            'start_date' => 'required',
            'analysis_word' => 'required',
            'analysis_timing' => 'required'
        ]);

        // パラメータを取得
        $params = AnalysisRequestService::getRequestParameters($request);
        $id = AnalysisRequestService::saveStartParameters($params);
        return response($id, 200);

        // wordcloud 解析用のファイルpath を作成する
        // TODO: uuid をつかって一意なファイル名を作成するようにしているが、念の為そのファイルが既に作成されていないかチェックすべき
        // TODO: a6s-cloud-batch 処理成功後にこのファイルを削除する処理を追加すべき
        $uuid = (string) str::uuid();
        $tweetsFileForWordcloud = $uuid . ".txt";
        $imageFileForWordcloud = $uuid . ".png";
        $localStorage = Storage::disk('local');
        $localStoragePath = $localStorage->getDriver()->getAdapter()->getPathPrefix();
        $publicStoragePath = Storage::disk('public')->getDriver()->getAdapter()->getPathPrefix();
        // logger(print_r('ファイル保存先: ' . $publicStoragePath . $imageFileForWordcloud . ', URL -> ' . asset('storage/' . $imageFileForWordcloud), true));

        // twitterデータ取得
        $twitter_config = config('twitter');
        $this->twitter_client = new TwitterOAuth(
            $twitter_config["api_key"],
            $twitter_config["secret_key"],
            $twitter_config["access_token"],
            $twitter_config["token_secret"]
        );


        // twitter serch
        // 抽出日付形式をハイフンに変換
        $format_date = AnalysisRequestService::formatDate($params["start_date"]);
        $params = ['q'=> $params["analysis_word"],
                   'count'=> 100,
                   'result_type'=>'recent',
                   'since'=> $format_date["target_start_date"].'_JST',
                   'until'=> $format_date["target_end_date"].'_JST',
                  ];
        $searchTweet = $this->twitter_client->get("search/tweets", $params);
        // ツイートデータを確認
        // logger(print_r($searchTweet->statuses, true));

        // サマリ件数を計算
        $total_retweet = 0;
        $total_favorite = 0;
        $total_tweet = 0;
        $total_users_map = array();

        // 暫定的に最大10回のリクエストをする(1000件取得)
        for ($i=0; $i<1; $i++) {
            foreach($searchTweet->statuses as $key => $value){
                $tweet = new Tweets;
                $tweet->analysis_result_id = $aResult->id;
                $tweet->user_name = $value->user->name;
                $tweet->user_account = $value->user->screen_name;
                $tweet->text = $value->text;
                $tweet->retweet_count = $value->retweet_count;
                $tweet->favorite_count = $value->favorite_count;
                // $tweet->created_at = $value->created_at;
                $tweet->created_at = (new DateTime($value->created_at))
                                         ->setTimeZone(new DateTimeZone('Asia/Tokyo'))->format("Y/m/d H:i:s");
                $tweet->save();

                // ユーザ数カウント用のキーを登録
                $total_users_map[$tweet->user_account] = null;

                // サマリを計算
                $total_retweet = $total_retweet + $value->retweet_count;
                $total_favorite = $total_favorite + $value->favorite_count;
                $total_tweet = $total_tweet + 1;
                // ユーザ数をカウントする処理を追加する

                // wordcloud用のテキストファイルにtweet データを保存
                // TODO: a6s-cloud-batch に引数として`$localStoragePath . $tweetsFileForWordcloud` を渡して
                //       tweet データが保存されているファイルを教えてあげる必要がある
                $localStorage->append($tweetsFileForWordcloud, $value->text);
            }

            // 次のリクエストを投げるためのpramerをセット
            if(!isset($value->id)){
                break;
            }
            $params["max_id"] = $value->id - 1;

            // 次のツイートデータを取得
            $searchTweet = $this->twitter_client->get("search/tweets", $params);

            // 0件なら終了
            $tweetCount = count($searchTweet->statuses);
            if($tweetCount == 0){
                break;
            }
        }

        // logger(print_r('Total user num -> ' . count($total_users_map) , true));
        $aResult->user_count = count($total_users_map);
        $aResult->save();

        // wordcloudを実行

        // logger(print_r('python3 ../../a6s-cloud-batch/src/createWordCloud.py '
        //     . $localStoragePath . $tweetsFileForWordcloud
        //     . '../../RictyDiminished/RictyDiminished-Bold.ttf /var/www/result.png', true));
        $process = new Process([
            'python3',
            '../../a6s-cloud-batch/src/createWordCloud.py',
            $localStoragePath . $tweetsFileForWordcloud,
            '../../RictyDiminished/RictyDiminished-Bold.ttf',
            $publicStoragePath . $imageFileForWordcloud
        ]);
        // TODO: 出力したファイルをDB に保存する処理を追加する
        $process->run();
        if (!$process->isSuccessful()) {
            $aResult->status = 3;
            $aResult->save();

            $e = new ProcessFailedException($process);
            logger(print_r($e->getMessage(), true));
            logger(print_r($e->getTraceAsString(), true));

            return response($aResult->id, 500);
        }

        // update処理
        $aResult->status = 2;
        $aResult->favorite_count = $total_favorite;
        $aResult->tweet_count = $total_tweet;
        $aResult->favorite_count = $total_favorite;
        $aResult->retweet_count = $total_retweet;
        $aResult->image = $imageFileForWordcloud;
        $aResult->save();

        // word cloudの画像を添付してツイートをする
        // ※動作確認する場合はコメントアウトを外してくだい
        // TODO:投稿文言は要検討
        // $media1 = $this->twitter_client->upload('media/upload', ['media' => $localStoragePath . $imageFileForWordcloud]);
        // $parameters = [
        //     'status' => "実装テストちゅうです！！\nテスト",
        //     'media_ids' => implode(',', [$media1->media_id_string]),
        // ];
        // $result = $this->twitter_client->post('statuses/update', $parameters);

        // IDを取得を返す
        return response($aResult->id, 200);
    }
}
