<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\UvaTopic;
use App\Models\Post;
use App\Models\User;
use App\Models\UserLike;
use Illuminate\Support\Facades\Cache;

class PostController extends Controller
{
    public function user_post_check(Request $data)
    {
        return response()->json(['check' => Post::find($data->post_id)->user_id == Auth::user()->id], 200);
    }
    public function post(Request $data)
    {
        $validator = Validator::make($data->all(), [
            'serial' => 'required|exists:uva_topics,serial',
            'video_url' => 'required|active_url',
            'content' => 'required',
        ], [
            'required' => '欄位沒有填寫完整!',
            'video_url.active_url' => '請放入正確的youtube影片網址',
            'serial.exists' => '題目編號不存在',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 402);
        }
        preg_match("#(?<=v=)[a-zA-Z0-9-]+(?=&)|(?<=v\/)[^&\n]+(?=\?)|(?<=v=)[^&\n]+|(?<=youtu.be/)[^&\n]+#", $data->video_url, $matches);
        if (count($matches) == 0) {
            return response()->json(['error' => '請放入正確的youtube影片網址'], 402);
        }
        $video_id = $matches[0];
        $video_pic_url = 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg';
        $headers = @get_headers($video_pic_url);
        if (!$headers || $headers[0] == 'HTTP/1.1 404 Not Found') {
            $video_pic_url = 'https://img.youtube.com/vi/' . $video_id . '/mqdefault.jpg';
        }

        if (Post::find($data->post_id)) { //更新POST
            if (Post::find($data->post_id)->user_id == Auth::user()->id) {
                Post::find($data->post_id)->update([
                    'uva_topic_id' => UvaTopic::get_uva_topic_id($data->serial),
                    'video_url' => 'https://www.youtube.com/watch?v=' . $video_id,
                    'video_id' => $video_id,
                    'video_pic_url' => $video_pic_url,
                    'content' => $data->content,
                    'code' => $data->code,
                    'code_type' => $data->code_type,
                    'code_editor_type' => $data->code_editor_type,
                ]);
                return response()->json(['success' => '成功更新貼文', 'post_id' => $data->post_id], 200);
            } else {
                return response()->json(['error' => '權限不符'], 200);
            }
        } else {
            $post = Post::create([  //添加POST
                'user_id' => Auth::user()->id,
                'uva_topic_id' => UvaTopic::get_uva_topic_id($data->serial),
                'video_url' => $data->video_url,
                'video_id' => $video_id,
                'video_pic_url' => $video_pic_url,
                'content' => $data->content,
                'code' => $data->code,
                'code_type' => $data->code_type,
                'code_editor_type' => $data->code_editor_type,
            ]);
            return response()->json(['success' => '成功創立貼文', 'post_id' => $post->id], 200);
        }
    }
    public function del_post(Request $data)
    {
        $validator = Validator::make($data->all(), [
            'post_id' => 'required|exists:posts,id',
        ], [
            'required' => '欄位沒有填寫完整!',
            'post_id.exists' => '貼文不存在',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 402);
        }
        $Post = Post::where([
            'id' => $data->post_id
        ]);
        if (Auth::user()->id != $Post->first()->user_id)
            return response()->json(['error' => '權限不符'], 200);
        else {
            $Post->delete();
            return response()->json(['success' => '成功刪除貼文'], 200);
        }
    }
    public function like_post(Request $data)
    {
        $lock = Cache::lock('key', 5);
        if (!$lock->get()) {
            return response()->json(['error' => '操作過於頻繁'], 402);
        }
        $validator = Validator::make($data->all(), [
            'post_id' => 'required|exists:posts,id',
            'dislike_or_like' => 'required', //-1 1
        ], [
            'required' => '欄位沒有填寫完整!',
            'post_id.exists' => '貼文不存在',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 402);
        }
        $dislike_or_like = $data->dislike_or_like;
        if ($dislike_or_like != 1 && $dislike_or_like != -1) {
            return response()->json(['error' => 'dislike_or_like只限於-1 or 1'], 402);
        }
        $user_like = UserLike::where([
            'user_id' => Auth::user()->id,
            'post_id' => $data->post_id
        ])->whereNull('comment_id')->first();

        $post = Post::find($data->post_id);

        if ($user_like === null) {
            UserLike::create([
                'user_id' => Auth::user()->id,
                'post_id' => $data->post_id,
                'dislike_or_like' => $dislike_or_like,
            ]);
            if ($dislike_or_like == 1) {
                $post->increment('likes');
            } else if ($dislike_or_like == -1) {
                $post->decrement('likes');
            }
        } else {
            if ($dislike_or_like == 1 && $user_like->dislike_or_like == -1) {
                $user_like->delete();
                $post->increment('likes');
            } else if ($dislike_or_like == -1 && $user_like->dislike_or_like == 1) {
                $user_like->delete();
                $post->decrement('likes');
            }
        }
        $user_like = UserLike::where([
            'user_id' => Auth::user()->id,
            'post_id' => $data->post_id
        ])->whereNull('comment_id')->first();

        $now_post_like = Post::find($data->post_id)->likes;
        $lock->release();

        return response()->json(['success' => '更新喜歡狀態成功', 'user_post_like' => $user_like?->dislike_or_like, 'now_post_like' => $now_post_like], 200);
    }
    public function get_post(Request $data)
    {
        $post_id = $data->post_id;
        if ($post_id != '') { //單一篇post
            try {
                $posts = Post::find($post_id);
                $posts = self::tidy_post($posts);
            } catch (\Throwable $th) {
                return response()->json(['success' => '貼文不存在'], 402);
            }
        } else { //多篇post

            $star = collect($data->star); //選幾星
            $sort = $data->sort; //0 or null新 1舊 2心多 3心少 4留言多 5留言少
            $user_account = $data->user_account;
            $code_type = collect($data->code_type);
            $serial = $data->serial;
            if ($user_account) {
                $user_id = User::where('account', $user_account)->first()->id;
                $posts = Post::where('user_id', $user_id)->get();
            } else {
                if ($serial)
                    $posts = UvaTopic::where('serial', $serial)->first()->Post;
                else
                    $posts = Post::all();
            }
            if (count($star) != 0) {
                $posts = $posts->map(function ($item, $key) use ($star) {
                    if ($star->contains($item->UvaTopic->star)) {
                        return $item;
                    }
                });
            }
            if (count($code_type) != 0) {
                $code_type = $code_type->map(function ($item, $key) {
                    switch ($item) {
                        case '14':
                            return 'C';
                            break;
                        case '15':
                            return 'C++';
                            break;
                        case '16':
                            return 'Java';
                            break;
                        case '17':
                            return 'Python';
                            break;
                    }
                });
                $posts = $posts->filter()->values(); //清null 以防上方篩star 出現null 導致$item->code_type 出錯
                $posts = $posts->map(function ($item, $key) use ($code_type) {
                    if ($code_type->contains($item->code_type)) {
                        return $item;
                    }
                });
            }
            switch ($sort) {
                case 0:
                    $posts = $posts->sortByDesc('created_at');
                    break;
                case 1:
                    $posts = $posts->sortBy('created_at');
                    break;
                case 2:
                    $posts = $posts->sortByDesc('created_at')->sortByDesc('likes');
                    break;
                case 3:
                    $posts = $posts->sortByDesc('created_at')->sortBy('likes');
                    break;
                case 4:
                    $posts = $posts->sortByDesc('created_at')->sortByDesc('comments_count');
                    break;
                case 5:
                    $posts = $posts->sortByDesc('created_at')->sortBy('comments_count');
                    break;
                default:
                    $posts = $posts->sortByDesc('created_at');
                    break;
            }
            $posts = $posts->filter()->values(); //清null
            $posts = $posts->map(
                function ($item, $key) {
                    return self::tidy_post($item);
                }
            );
        }
        return response()->json(['success' => $posts], 200);
    }
    public function tidy_post($item)
    {
        return collect([
            'id' => $item['id'],
            'user_id' => $item['user_id'],
            'user_account' => $item->User->account,
            'user_name' => $item->User->name,
            'user_pic_url' => $item->User->pic_url,
            'uva_topic' => $item->UvaTopic,
            'video_url' => $item['video_url'],
            'video_id' => $item['video_id'],
            'video_pic_url' => $item['video_pic_url'],
            'content' => $item['content'],
            'likes' => $item['likes'],
            'comments_count' => $item['comments_count'],
            'code' => $item['code'],
            'code_editor_type' => $item['code_editor_type'],
            'code_type' => $item['code_type'],
            'created_at' => $item['created_at']->format('Y/m/d H:i:s'),
            'updated_at' => $item['updated_at']->format('Y/m/d H:i:s'),
        ]);
    }
}
