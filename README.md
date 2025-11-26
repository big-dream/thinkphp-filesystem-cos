# ThinkPHP 8 COS 文件系统驱动

适用于 topthink/think-filesystem 的腾讯云对象储存(COS)驱动。

## 安装
```
composer require big-dream/thinkphp-filesystem-cos:dev-main
```

## 配置
编辑 `config/filesystem.php` 文件，在该文件里增加一项磁盘配置信息。

```php
<?php

return [
    // 默认磁盘
    'default' => env('filesystem.driver', 'local'),
    // 磁盘列表
    'disks'   => [
        'local'  => [
            'type' => 'local',
            'root' => app()->getRuntimePath() . 'storage',
        ],
        'public' => [
            // 磁盘类型
            'type'       => 'local',
            // 磁盘路径
            'root'       => app()->getRootPath() . 'public/storage',
            // 磁盘路径对应的外部URL路径
            'url'        => '/storage',
            // 可见性
            'visibility' => 'public',
        ],

        /** 以下为新增的磁盘配置信息 **/
        'cos' => [
            'type' => \bigDream\thinkphp\filesystem\Cos::class,
            // 磁盘路径对应的外部URL路径
            'url'        => 'https://example-10020201024.cos.ap-guangzhou.myqcloud.com',
            // 请求协议
            'schema'     => 'http',
            // 可见性
            'visibility' => '',
            // 地域
            'region'     => 'ap-guangzhou',
            // 应用ID
            'app_id'     => 10020201024,
            // 密钥ID
            'secret_id'  => 'AKIDsiQzQla780mQxLLU2GJCxxxxxxxxxxx',
            // 密钥KEY
            'secret_key' => 'b0GMH2c2NXWKxPhy77xhHgwxxxxxxxxxxx',
            // 储存桶名称
            'bucket'     => 'example',
            // 路径前缀
            'prefix'     => '',
        ],
        /** 以上为新增的磁盘配置信息 **/

        // 更多的磁盘配置信息
    ],
];

```

## 使用
### 示例控制器代码
app/controller/Index.php：
```php
<?php
namespace app\controller;

class Index
{
    public function index()
    {
        // 如果是GET请求则显示上传表单界面
        if (\think\facade\Request::isGet()) {
            return '<form action="" method="post" enctype="multipart/form-data">'
                . '<input type="file" name="file">'
                . '<button type="submit">Upload</button>'
                . '</form>';
        }

        try {
            // 获取上传的文件，如果有上传错误，会抛出异常
            $file = \think\facade\Request::file('file');
            // 如果上传的文件为null，手动抛出一个异常，统一处理异常
            if (null === $file) {
                // 异常代码使用UPLOAD_ERR_NO_FILE常量，方便需要进一步处理异常时使用
                throw new \Exception('请上传文件', UPLOAD_ERR_NO_FILE);
            }

            // 获取磁盘实例
            $disk = \think\facade\Filesystem::disk('cos');

            // 保存文件到 avatar 目录
            $path = $disk->putFile('avatar', $file);
            // 获取 URL
            $url = $disk->url($path);
        } catch (\Exception $e) {
            // 如果上传时有异常，会执行这里的代码，可以在这里处理异常
            return json([
                'code' => 1,
                'msg'  => $e->getMessage(),
            ]);
        }

        $info = [
            // 文件路径：avatar/a4/e7b9e4ce42e2097b0df2feb8832d28.jpg
            'path' => $path,
            // URL路径：https://example-10020201024.cos.ap-guangzhou.myqcloud.com/storage/avatar/a4/e7b9e4ce42e2097b0df2feb8832d28.jpg
            'url'  => $url,
            // 文件大小（字节）
            'size' => $file->getSize(),
            // 文件名：读书顶个鸟用.jpg
            'name' => $file->getOriginalName(),
            // 文件MINE：image/jpeg
            'mime' => $file->getMime(),
        ];
        halt($info);
    }
}
```

## 依赖

* https://github.com/top-think/think-filesystem/tree/3.0
* https://github.com/overtrue/qcloud-cos-client