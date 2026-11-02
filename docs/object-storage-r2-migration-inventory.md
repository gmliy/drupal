# 历史文件迁移清单

盘点时间：2026-11-02 18:07–18:10 UTC。只读统计，没有复制对象，没有修改 File Entity URI，没有删除本地文件。

本轮 `s3fs-public/` 和 `s3fs-private/` 在测试对象删除后的对象数都是 0。预检查脚本随后再次扫描，结论相同：可复制 128，本地缺失 9，远程一致 0，已复制 0。

## 统计

| 项目 | 数量 |
| --- | ---: |
| File Entity | 137 |
| 数据库记录的总字节 | 75,223,025 |
| public:// | 128 |
| 公开文件实际存在 | 128 |
| 公开文件数据库字节 | 75,177,632 |
| 公开文件具备迁移条件 | 128 |
| private:// | 9 |
| 私有文件实际存在 | 0 |
| 私有文件数据库字节 | 45,393 |
| 可执行迁移清单中的私有文件 | 0 |
| 图片样式目录 | 15 |
| 图片样式衍生文件 | 766 |
| R2 `s3fs-public/` | 0 |
| R2 `s3fs-private/` | 0 |

公开文件 MIME：JPEG 105，WebP 11，PNG 10，MP4 2。SHA-256 有 120 个不同值，其中 4 组哈希重复，涉及 12 个文件。最大文件 4,922,910 字节，最小 4,156 字节。目标键是 `s3fs-public/` 加上 `public://` 后面的相对路径。远程对象不存在，所以复制会是新建，不是覆盖。

## 缺失的私有文件

9 条记录都是 `application/zip`，状态为永久文件，本地路径不存在，SHA-256 无法计算。`file_usage` 指向 `xedc_core` / `xedc_import_task`，对应任务行已经不在 `xedc_import_task` 表里。公开文件目录和私有目录都没有同名副本，也没有另一条相同文件名的 File Entity。没有历史 R2 对象。没有可恢复来源。没有创建替代文件。

这些记录不进入可执行迁移清单。建议以后由人工确认导入任务已废弃后，再决定是否删除这 9 条文件实体。本次没有删除。

| fid | 字节 | 引用 | 阻塞原因 |
| ---: | ---: | --- | --- |
| 166 | 5047 | 导入任务 19，任务行已不存在 | 本地文件缺失，无备份、无远程对象 |
| 219 | 5047 | 导入任务 35，任务行已不存在 | 本地文件缺失，无备份、无远程对象 |
| 272 | 5047 | 导入任务 15，任务行已不存在 | 本地文件缺失，无备份、无远程对象 |
| 326 | 5047 | 导入任务 32，任务行已不存在 | 本地文件缺失，无备份、无远程对象 |
| 379 | 5047 | 导入任务 48，任务行已不存在 | 本地文件缺失，无备份、无远程对象 |
| 481 | 5047 | 导入任务 91，任务行已不存在 | 本地文件缺失，无备份、无远程对象 |
| 491 | 5039 | 导入任务 107，任务行已不存在 | 本地文件缺失，无备份、无远程对象 |
| 505 | 5035 | 导入任务 123，任务行已不存在 | 本地文件缺失，无备份、无远程对象 |
| 520 | 5037 | 导入任务 139，任务行已不存在 | 本地文件缺失，无备份、无远程对象 |

报告不列出这 9 个压缩包的文件名和私有路径。

## 图片样式

配置里有 17 个图片样式。下面 15 个目录里共有 766 个衍生文件。`max_2600x2600` 和 `wide` 已配置，当前没有衍生目录。

| 样式 | 衍生文件 |
| --- | ---: |
| `images_450x800` 图片手机壁纸(450x800) | 75 |
| `max_1300x1300` 最大 1300x1300 | 37 |
| `max_2600x2600` 最大 2600x2600 | 0 |
| `max_325x325` 最大 325x325 | 2 |
| `max_650x650` 最大 650x650 | 56 |
| `media_library` 媒体库缩略图 (220×220) | 22 |
| `medium` 中 (220×220) | 123 |
| `meitu_card_cover` 美图卡片 480×800 | 69 |
| `photo_card_640` 图片卡片 640x853 (WebP) | 65 |
| `photo_gallery_1300` 美图详情 1300 | 91 |
| `photo_gallery_650` 美图详情 650 | 91 |
| `thumbnail` 缩略图 (100×100) | 92 |
| `video_cover` 视频封面 (640×360) | 5 |
| `wide` 宽 (1090) | 0 |
| `xa_cover` XA 封面 400x300 | 12 |
| `xa_hero` XA 正文封面 宽1200 | 20 |
| `xa_hero_840` XA hero 840 | 6 |

处理策略：

- 766 个衍生文件留在本地 `public://styles`，不删除，也不列入必须复制的清单。
- 源图都在公开文件里。Drupal Image Style 可以在源图仍由本地 `PublicStream` 提供时重新生成衍生文件。
- 当前接管关闭，生成结果继续写本地目录，不会写入 R2。
- 接管打开后，S3FS 才会替换图片样式下载控制器。那一步尚未执行，不能把“会正确写入 R2”记成已通过。
- 正式切换前用一个样式做灰度，确认衍生文件出现在 `s3fs-public/styles/`，并且旧的本地文件仍保留。
- 衍生 URL 带 `itok`。边缘如果缓存这些 URL，样式定义变化后需要按 URL 清理，不能靠把现有对象改成一年 `immutable`。

## 公开文件可执行清单

128 条全部 `exists=yes`、`ready=yes`。私有文件没有出现在这张表里。

| fid | 字节 | MIME | SHA-256 | 目标键 | URI |
| ---: | ---: | --- | --- | --- | --- |
| 1 | 163198 | image/jpeg | `6b740ca3f622993990e3a9d6fe03589c57f74ed04c8a70674c3d4c1e19fb62cb` | `s3fs-public/2026-10/IMG_3009.jpeg` | `public://2026-10/IMG_3009.jpeg` |
| 2 | 1039999 | image/jpeg | `737a56aee868503c44611e3de17710c6101aa390a46a98a2ddb6ab8956add991` | `s3fs-public/2026-10/IMG_3008.jpeg` | `public://2026-10/IMG_3008.jpeg` |
| 4 | 4922910 | image/png | `42b2b89290195b23316032734f3c2754d83bd405f9508486eef3af998ddb0d71` | `s3fs-public/2026-10/IMG_1204.png` | `public://2026-10/IMG_1204.png` |
| 5 | 2255011 | video/mp4 | `e6335363255565ccc6cf1349423e229b1c758ec3c8971bb0f5a6174bf2c372d0` | `s3fs-public/2026-10/plel0QtzdBBA16i0w6fjpggSrVXB8c8c5IOZ7IXwP_EFYxKKyT_8jFphUD8zgyqvTe8QNyBHNK4VbYSWl1_v1rDOyl-d1eGjJWMzLyGwiDxyE8ozgVIVx2RXY1qkPJ3vkHolGA5tDI9vSm6iKtSvQoRLqxYHfdOqOpl1vIQUXI2TSmJ7bUGdc3EIZGRMQAir.mp4` | `public://2026-10/plel0QtzdBBA16i0w6fjpggSrVXB8c8c5IOZ7IXwP_EFYxKKyT_8jFphUD8zgyqvTe8QNyBHNK4VbYSWl1_v1rDOyl-d1eGjJWMzLyGwiDxyE8ozgVIVx2RXY1qkPJ3vkHolGA5tDI9vSm6iKtSvQoRLqxYHfdOqOpl1vIQUXI2TSmJ7bUGdc3EIZGRMQAir.mp4` |
| 6 | 4156 | image/png | `0c3fe83b32825a260c5263fcc1055bdf639e8298300f68c1e17e4e4ea0ceb9eb` | `s3fs-public/media-icons/generic/video.png` | `public://media-icons/generic/video.png` |
| 7 | 1722143 | image/jpeg | `d28e669b0d2e6ca18e1c076e119ea06eb90b8728e55ecbe12e1e7f32822636ee` | `s3fs-public/2026-10/IMG_1468.jpeg` | `public://2026-10/IMG_1468.jpeg` |
| 8 | 39808 | image/webp | `1affa615d523d09fc084db422cf694bff171551653631852d813ce10a7823670` | `s3fs-public/2026-10/oMQqAQDZAAAiA9wxgEFzqMNDwfkhfPTEAACCpI~tplv-dy-aweme-images-v2_1440_2560_q75.webp` | `public://2026-10/oMQqAQDZAAAiA9wxgEFzqMNDwfkhfPTEAACCpI~tplv-dy-aweme-images-v2_1440_2560_q75.webp` |
| 9 | 4037606 | image/png | `dc426e4f4addb389d347906b5f6169c1c7682d5b7c0c8fdb12c8c31c9ffd2978` | `s3fs-public/2026-10/IMG_1090.png` | `public://2026-10/IMG_1090.png` |
| 10 | 108828 | image/webp | `fe9e2bdf97421b1bebf6934cad2709550614553f14a35d1f9f748a51e01f9853` | `s3fs-public/2026-10/oo3X94ssqECAAAAmiCVFCBkEItDAjSA00gfyTe~tplv-dy-vqe2-v2_1440_2560_q75.webp` | `public://2026-10/oo3X94ssqECAAAAmiCVFCBkEItDAjSA00gfyTe~tplv-dy-vqe2-v2_1440_2560_q75.webp` |
| 11 | 72140 | image/webp | `380f79776d9e5da91ee8eb6a5a6a36ae3803116a5f454415dbb39747d599f70a` | `s3fs-public/comments/2026-10/okJREuiPAGnJFJWsEABAIhV4e51wECCAAgiOfQ~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` | `public://comments/2026-10/okJREuiPAGnJFJWsEABAIhV4e51wECCAAgiOfQ~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` |
| 14 | 173184 | image/png | `4c5a66383bd33673fbe28005ab82c1b40a61a9e439a76ff0168c856bbddfb07c` | `s3fs-public/comments/2026-10/IMG_2154_0.png` | `public://comments/2026-10/IMG_2154_0.png` |
| 19 | 118246 | image/webp | `15d05e7c58290c6c0bf13fb2e92930c687039237d2c1d509da7426becde53ea3` | `s3fs-public/2026-10/oQrhnAnB1CE4JoRiiAAgcAE5AEOsfVPwGeIJCJ~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` | `public://2026-10/oQrhnAnB1CE4JoRiiAAgcAE5AEOsfVPwGeIJCJ~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` |
| 20 | 126884 | image/webp | `22a183e9bfeb3109a45f13bef91ec9ec15f4c81ac3fb1fa8b05a961b11903aa8` | `s3fs-public/2026-10/ogMDfABVsECEJieO15JJhAnAwE3sCGPIAA4giR~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` | `public://2026-10/ogMDfABVsECEJieO15JJhAnAwE3sCGPIAA4giR~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` |
| 21 | 132964 | image/webp | `90203e57b293ba53a61859750bc1bae5c71481fd54c839923f4bb8e45bbc77cc` | `s3fs-public/2026-10/o8g5niBkeAEMiIAJTsiEGhCAEARwJ4AOV1ftJC~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` | `public://2026-10/o8g5niBkeAEMiIAJTsiEGhCAEARwJ4AOV1ftJC~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` |
| 22 | 128898 | image/webp | `3167e5715aaf18f96c875368e815b82edee497e6cad59a710cd29a505cd4f774` | `s3fs-public/2026-10/okJREuiPAGnJFJWsEABAIhV4e51wECCAAgiOfQ~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` | `public://2026-10/okJREuiPAGnJFJWsEABAIhV4e51wECCAAgiOfQ~tplv-dy-vqe2-sr-v2_1440_2560_q80.webp` |
| 23 | 361090 | image/webp | `94dc07784055062e323bd14dfa92c13aad554db8f9dfd8e5467e4b21ff974377` | `s3fs-public/2026-10/oY2BrhbAPEjOiBRAACOnAIO7nMPAa4zwLSict~tplv-dy-vqe2-v2_1440_1913_q80.webp` | `public://2026-10/oY2BrhbAPEjOiBRAACOnAIO7nMPAa4zwLSict~tplv-dy-vqe2-v2_1440_1913_q80.webp` |
| 24 | 329480 | image/webp | `717b65c26f9ae353b9245ea8cb725177f912ed86e4bf48e60d04fff73e00ec86` | `s3fs-public/2026-10/o0Q9MeqA3JSihEU2DEIdGuFFfAAEcwfsE5AA2g~tplv-dy-vqe2-v2_1440_1937_q80.webp` | `public://2026-10/o0Q9MeqA3JSihEU2DEIdGuFFfAAEcwfsE5AA2g~tplv-dy-vqe2-v2_1440_1937_q80.webp` |
| 25 | 351788 | image/webp | `69b8ca0e8598f3ccb967718bac53579b3543a3b7cea273f7dad993510fb3f15a` | `s3fs-public/2026-10/o0O7zibQArbnthjBIwaiPZMBEA2AcRJCcAPAO~tplv-dy-vqe2-v2_1440_1901_q80.webp` | `public://2026-10/o0O7zibQArbnthjBIwaiPZMBEA2AcRJCcAPAO~tplv-dy-vqe2-v2_1440_1901_q80.webp` |
| 26 | 376458 | image/jpeg | `5571e2fb7f82a40c698c70d6b11ebdb4bae1f2a1baabe908a7651c9bebb4f902` | `s3fs-public/gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-003.jpg` | `public://gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-003.jpg` |
| 27 | 398627 | image/jpeg | `2db22d467eb8c1bc12eba5bc59fad1d081606e6dcdbaacb3ec18f0d5fdde29d3` | `s3fs-public/gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-001.jpg` | `public://gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-001.jpg` |
| 28 | 405135 | image/jpeg | `41994b728c999b0d4c62e6137175c393b2b0c78afc5bebe5b8c498b61f8f8bc6` | `s3fs-public/gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-002.jpg` | `public://gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-002.jpg` |
| 29 | 394105 | image/jpeg | `9c53c78749948dfeec7040fcd2a0faeb70581334241b92d0cc806a499f6b783d` | `s3fs-public/gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-004.jpg` | `public://gallery/2026-10/ceshikuaishoujfjfjfnfnfnnfnfdnndnd-004.jpg` |
| 30 | 398627 | image/jpeg | `2db22d467eb8c1bc12eba5bc59fad1d081606e6dcdbaacb3ec18f0d5fdde29d3` | `s3fs-public/gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-001.jpg` | `public://gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-001.jpg` |
| 31 | 405135 | image/jpeg | `41994b728c999b0d4c62e6137175c393b2b0c78afc5bebe5b8c498b61f8f8bc6` | `s3fs-public/gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-002.jpg` | `public://gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-002.jpg` |
| 32 | 376458 | image/jpeg | `5571e2fb7f82a40c698c70d6b11ebdb4bae1f2a1baabe908a7651c9bebb4f902` | `s3fs-public/gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-003.jpg` | `public://gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-003.jpg` |
| 33 | 394105 | image/jpeg | `9c53c78749948dfeec7040fcd2a0faeb70581334241b92d0cc806a499f6b783d` | `s3fs-public/gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-004.jpg` | `public://gallery/2026-10/ceshitubijidejidejidejiujiudajidanandanudanandanu-004.jpg` |
| 34 | 398627 | image/jpeg | `2db22d467eb8c1bc12eba5bc59fad1d081606e6dcdbaacb3ec18f0d5fdde29d3` | `s3fs-public/gallery/2026-10/ceshidierciquzhong-001.jpg` | `public://gallery/2026-10/ceshidierciquzhong-001.jpg` |
| 35 | 394105 | image/jpeg | `9c53c78749948dfeec7040fcd2a0faeb70581334241b92d0cc806a499f6b783d` | `s3fs-public/gallery/2026-10/ceshidierciquzhong-003.jpg` | `public://gallery/2026-10/ceshidierciquzhong-003.jpg` |
| 36 | 376458 | image/jpeg | `5571e2fb7f82a40c698c70d6b11ebdb4bae1f2a1baabe908a7651c9bebb4f902` | `s3fs-public/gallery/2026-10/ceshidierciquzhong-004.jpg` | `public://gallery/2026-10/ceshidierciquzhong-004.jpg` |
| 37 | 405135 | image/jpeg | `41994b728c999b0d4c62e6137175c393b2b0c78afc5bebe5b8c498b61f8f8bc6` | `s3fs-public/gallery/2026-10/quzhongfudeceshitu-002.jpg` | `public://gallery/2026-10/quzhongfudeceshitu-002.jpg` |
| 38 | 316760 | image/jpeg | `06f69304867402a41706639ad688234bbc0b8315d4ebb739c7c5cbe66f96f51f` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-001.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-001.jpg` |
| 39 | 358135 | image/jpeg | `ae4e003c5e1353800dcca590c540291cce3ee8465839cc28ae2a0b95f1f43710` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-002.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-002.jpg` |
| 40 | 287678 | image/jpeg | `5d312f9258c9cfc239fde2a4bc8662def21a9489be77618879b086f64cd11818` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-003.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-003.jpg` |
| 41 | 317816 | image/jpeg | `582531f9c7246b4062a730475cca75d603fbe8ffae06db9c0ff842a195c645b5` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-004.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-004.jpg` |
| 42 | 322379 | image/jpeg | `e1250dacd7fcf68bc935408cabe8b293aefb148e33e9564a6b02088fa7881129` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-005.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-005.jpg` |
| 43 | 328219 | image/jpeg | `560e649713534ce71a6c3e81333f0d7bc158546c29b10d13e8699d387f6fa633` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-006.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-006.jpg` |
| 44 | 330526 | image/jpeg | `16522f8ac63b48b1e065558f1cbf1b213f69d905504b663d015ceb2303bd938b` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-007.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-007.jpg` |
| 45 | 325460 | image/jpeg | `b58919cafa4f56b8c517393f8a0a7e0ce3b8e626a4c608fbda776605eaeba243` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-008.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-008.jpg` |
| 46 | 325573 | image/jpeg | `46a5268bcf4c26c6f00b81982bb9542ec64a856464ccec8057bb7e64f9c581f2` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-009.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-009.jpg` |
| 47 | 311111 | image/jpeg | `e4cb8ee964a738c0455900fd5e9f0cab1d5e02915c24741dda54f8c7fb9b0e42` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-010.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-010.jpg` |
| 48 | 316001 | image/jpeg | `0b93be608dfdf1eb6cad01beeb17276f19db41dbd76c1fd61f4a4570a89c3433` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-011.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-011.jpg` |
| 49 | 314666 | image/jpeg | `ac49f7e698e9a3c6e48b857b99cbc05a54e7d4ee39924db4418164134c4a7e5e` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-012.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-012.jpg` |
| 50 | 302447 | image/jpeg | `1a0d1e023e284b05389464f577d75a3a34904fcd667dfc6b465befce8afc1c81` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-013.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-013.jpg` |
| 51 | 325207 | image/jpeg | `cee19d4f3b3a30264b877a05ff916000e0c38125036d3b674c80fbe9c2c45d9d` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-014.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-014.jpg` |
| 52 | 284361 | image/jpeg | `878b6e137c7bf530bc47df2aa4d1df2a0ea333d03008838ead4bc50aaf058ac0` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-016.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-016.jpg` |
| 53 | 281701 | image/jpeg | `353216efc8114f69e5807abd5125b1dac99391a617cb7466635ba34309822388` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-015.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-015.jpg` |
| 54 | 293570 | image/jpeg | `13473e0194fd3c8cad6d26118e1fea6f409f777905b2cca2dccd29d04c94f819` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-017.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-017.jpg` |
| 55 | 268425 | image/jpeg | `8c92f6193f1dbec2f369ad4ae808e74500acc09be329d460d9b81ae2b532f47d` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-018.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-018.jpg` |
| 56 | 308003 | image/jpeg | `a043b17a37a2f61e116829ee6abe420f2f153a6eae73ef2a736da885c181c7a1` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-019.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-019.jpg` |
| 57 | 307578 | image/jpeg | `71474d6ea1aec40374ed1f1ee1d30673b732bdebeef665670d2656d1aea69369` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-020.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-020.jpg` |
| 58 | 304174 | image/jpeg | `7b8d787e1936b529cdc11f718dbb8b38e077d7ee5b78610d47b64b97b35539cd` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-022.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-022.jpg` |
| 59 | 307962 | image/jpeg | `df6c01c9882a6681f49b1ab0aa38d9cf0a1e9108591814553ed69a78e9f13b53` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-021.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-021.jpg` |
| 60 | 316518 | image/jpeg | `061aff5f6d3b5dcfcc2da66eeb5e36cb7c552d9feffd650b3b41005542a77b81` | `s3fs-public/gallery/2026-10/ceshitupianmojitupiancosplay-023.jpg` | `public://gallery/2026-10/ceshitupianmojitupiancosplay-023.jpg` |
| 61 | 310336 | image/jpeg | `3c6725f01eee50fbfc5ba0b687f7fb991eb65f7548496d46dc25c0c58145857c` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-001.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-001.jpg` |
| 62 | 302170 | image/jpeg | `c342fe50c2cad6060fe2de650bc327a5e3bdd43337671d0ab04102adf80302b4` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-002.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-002.jpg` |
| 63 | 350394 | image/jpeg | `c97682ae133f56985f82a48e0f4267146b6cb54370b6e4af666c498312444abe` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-003.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-003.jpg` |
| 64 | 297065 | image/jpeg | `6ff30bdbb408e769a4c7669d3f23110ceb64383800e0aba54c759c532225edbc` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-004.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-004.jpg` |
| 65 | 346403 | image/jpeg | `d55b7075e43bec141b052421a6a001c78bf110f2bd6948c3f943137b720ef56f` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-028.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-028.jpg` |
| 66 | 310014 | image/jpeg | `10936ee0805a36313171209bf2a9526e2977cff7c0a5cf518b449810868cbfe2` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-030.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-030.jpg` |
| 67 | 365859 | image/jpeg | `d071335d09935fa97cbb5829c1dffe02e8f88589ebb3011df381d67f4073aef3` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-029.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-029.jpg` |
| 68 | 307735 | image/jpeg | `0244a62495271ca25af56f98e941d8cb142c61887e7b2d0dae6e2b5094126070` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-031.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-031.jpg` |
| 69 | 353940 | image/jpeg | `2e6795858a55ad9859588691376cf9cdacb9ec44708a58ac28344b9a20ff63dd` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-032.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-032.jpg` |
| 70 | 411745 | image/jpeg | `ad0442d0b553c08312e8b58bad59b6c121a34d15361fcb0fac46cdabc783ef06` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-033.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-033.jpg` |
| 71 | 409221 | image/jpeg | `b9c57c639867d422dbc17acd17a37771167bf84507ba01c0e3985856517410d5` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-034.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-034.jpg` |
| 72 | 415302 | image/jpeg | `c78c5c3ebd2b0399c72facb9b97ff0db21d2ae205e5bc8d66448165c2c0813f6` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-035.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-035.jpg` |
| 73 | 371131 | image/jpeg | `1e9f9c65ec3965f8530798baa264a2090c13c310eeb1039f649890106f9825d3` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-036.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-036.jpg` |
| 74 | 307372 | image/jpeg | `f97f59eee48e45a4be5ebf092342c63ed899c3e9283c9bd7811d51d8c410d2f8` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-037.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-037.jpg` |
| 75 | 326349 | image/jpeg | `73c4a22b0c83d4015509c6e583d1f3fb150d95c160c922495934a7c206b79f92` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-038.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-038.jpg` |
| 76 | 335300 | image/jpeg | `a62fcd5c26a40e76fd90d93ddaf36bfc2021b640ad4b6ada104c755d3c0c3a43` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-039.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-039.jpg` |
| 77 | 307612 | image/jpeg | `fa1493eb9d6d78b9c5e35c6f8d3b48d99a20c99299544d21da6723a6ce1d2cee` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-040.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-040.jpg` |
| 78 | 397470 | image/jpeg | `76564b7318e8899ebbe9166c1a60c1c400c46c0ab3b2b580ba0d1465631cf25e` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-041.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-041.jpg` |
| 79 | 272414 | image/jpeg | `b62a03459c22ebccca11c8d99b06c710de0be8692a91782d925201d7fb041996` | `s3fs-public/gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-042.jpg` | `public://gallery/2026-10/cosplmojixiaolilihaoduohaoduohaoduo-042.jpg` |
| 80 | 382152 | image/jpeg | `d06092621155933c8e5bee7898a0eea1032fc9bd2d8b48211075a6afd1e46b18` | `s3fs-public/gallery/2026-10/ceshitupiandaxiaowenjiangeshihetupiandaxiaowenjian-001.jpg` | `public://gallery/2026-10/ceshitupiandaxiaowenjiangeshihetupiandaxiaowenjian-001.jpg` |
| 81 | 288726 | image/jpeg | `88cce047a86a94e0454ae7934ba95f1905ee961bb293e9afffecb342a261b7dc` | `s3fs-public/gallery/2026-10/ceshitupiandaxiaobiaotideng-001.jpg` | `public://gallery/2026-10/ceshitupiandaxiaobiaotideng-001.jpg` |
| 82 | 87996 | image/jpeg | `fc7a70c6748fec256ade0d1660eb574baffc9e1e0842f8706bcbaadfbcf433f9` | `s3fs-public/gallery/2026-10/chengshudeaaaa-001.jpg` | `public://gallery/2026-10/chengshudeaaaa-001.jpg` |
| 83 | 200651 | image/jpeg | `be00ac924804ced7f76ba8a8439fda854dc362cf04c781d60bc2f548aad1b031` | `s3fs-public/gallery/2026-10/piyiercishangchuandian-002.jpg` | `public://gallery/2026-10/piyiercishangchuandian-002.jpg` |
| 84 | 201957 | image/jpeg | `e2fdeeb6228e2cbe0cf7f7cc09bab9b8fde95869fff85a1ccdc1e82ebd2521b5` | `s3fs-public/gallery/2026-10/piyiercishangchuandian-001.jpg` | `public://gallery/2026-10/piyiercishangchuandian-001.jpg` |
| 85 | 296272 | image/jpeg | `9de7c34fb90acb2763f8307da2012947ec5527c9f5bcc26a7533f6ae6d38b827` | `s3fs-public/gallery/2026-10/piyiercishangchuandian-003.jpg` | `public://gallery/2026-10/piyiercishangchuandian-003.jpg` |
| 86 | 4627386 | video/mp4 | `56519d7694dfa9ad92ee8d6d6a449e9a89061a82d8298808f1a1464380b89580` | `s3fs-public/2026-10/oQ0H8zP6iMisAdLAvmDTiApwaO97AE3EBaBA9.mp4` | `public://2026-10/oQ0H8zP6iMisAdLAvmDTiApwaO97AE3EBaBA9.mp4` |
| 111 | 1604155 | image/jpeg | `f26ac58f2bac2c6ba6ba8cc6dcd896d3275d3cd68ff673acf7d33f6490b17456` | `s3fs-public/pictures/IMG_2664.jpeg` | `public://pictures/IMG_2664.jpeg` |
| 112 | 111884 | image/webp | `0054bf1faa740fcd55c09a368e68906c5e6c0c6a5f7a6b46462355c88530abe4` | `s3fs-public/2026-10/IMG_3224.webp` | `public://2026-10/IMG_3224.webp` |
| 391 | 100356 | image/jpeg | `aee2813d39ab57e98b8b28f1449c261a98351e317e4090f282e82c7ef2765468` | `s3fs-public/2026-11/n195-001.jpg` | `public://2026-11/n195-001.jpg` |
| 392 | 116344 | image/jpeg | `ee77b810c4d34944b89a4a6a926f5104dd66f67936905635aeffca1b3c1d3b7c` | `s3fs-public/2026-11/n195-002.jpg` | `public://2026-11/n195-002.jpg` |
| 393 | 116966 | image/jpeg | `1cad1b46500fe6c5f573c6be3a748f1f4e6b13a84c9901264ba9701fa9c33b1e` | `s3fs-public/2026-11/n195-003.jpg` | `public://2026-11/n195-003.jpg` |
| 394 | 116257 | image/jpeg | `a528c4cab57bd24123cb4b700af883f30094253fb7ea1dab2c37418a40c15106` | `s3fs-public/2026-11/n195-004.jpg` | `public://2026-11/n195-004.jpg` |
| 395 | 115647 | image/jpeg | `4aad6ad69aea97da46e0c10a41f6c591e8801b8c0d84bb602dee2f9ffccd0391` | `s3fs-public/2026-11/n195-005.jpg` | `public://2026-11/n195-005.jpg` |
| 396 | 141379 | image/jpeg | `62468f6aff8fcd648c48cc81f9f2a2684452eb4a2dd23c616888ab27b9063aca` | `s3fs-public/2026-11/n195-006.jpg` | `public://2026-11/n195-006.jpg` |
| 397 | 136263 | image/jpeg | `b685bfc2ef36195fe5ef6094ee481d00cf14487ff73ad7dd09287523fcb0e1c6` | `s3fs-public/2026-11/n195-007.jpg` | `public://2026-11/n195-007.jpg` |
| 398 | 191206 | image/jpeg | `d22bcdafb8bfa5682d2fbe71f5d5d010162c57fc82933922c7ce718604055805` | `s3fs-public/2026-11/n195-008.jpg` | `public://2026-11/n195-008.jpg` |
| 399 | 214484 | image/jpeg | `aaac16cd39b5b2a88ab927ab24d6e93bddfa8eea0bc05f364945571c43ed42bc` | `s3fs-public/2026-11/n195-009.jpg` | `public://2026-11/n195-009.jpg` |
| 400 | 180518 | image/jpeg | `99c866528b955ed5d5b32e483f01dca338e34dd8d878e3bcc6fa0160955e17f5` | `s3fs-public/2026-11/n195-010.jpg` | `public://2026-11/n195-010.jpg` |
| 401 | 155500 | image/jpeg | `8f59c0bfe27624135a056b4f387e8faeab45b69c0e409b682016267f8d911ae0` | `s3fs-public/2026-11/n195-011.jpg` | `public://2026-11/n195-011.jpg` |
| 402 | 152170 | image/jpeg | `0ae33857dd3ad61391d94c42d49abf92b3a24d38c25b556366b9402b92e0e530` | `s3fs-public/2026-11/n195-012.jpg` | `public://2026-11/n195-012.jpg` |
| 403 | 168079 | image/jpeg | `81cbb56d26af4bc349f5815d45faa52b0aeedcc982a39c2cd56cfb5b84b6e527` | `s3fs-public/2026-11/n195-013.jpg` | `public://2026-11/n195-013.jpg` |
| 404 | 146303 | image/jpeg | `34d1cd79b0ddb2d8fb326a557893000ee7654e6171b5c5e7d6b59e4f58489857` | `s3fs-public/2026-11/n195-014.jpg` | `public://2026-11/n195-014.jpg` |
| 405 | 211887 | image/jpeg | `116248cf8feb3466a3ec1db28e0fe927ac99ba9d209f1b982128b15292dbe65c` | `s3fs-public/2026-11/n195-015.jpg` | `public://2026-11/n195-015.jpg` |
| 406 | 201272 | image/jpeg | `55e366f583ed09ba8724230aec77193b050315e44b9a99d41c4c2688b2f9b652` | `s3fs-public/2026-11/n195-016.jpg` | `public://2026-11/n195-016.jpg` |
| 407 | 187501 | image/jpeg | `99e9635b3534a4e4a18eae2bdf0f61e282e6e0f6a0ae2b5939f49c31c4a9a7a9` | `s3fs-public/2026-11/n195-017.jpg` | `public://2026-11/n195-017.jpg` |
| 408 | 210339 | image/jpeg | `26f03706dd39cfd473abcae093c685ab5a890dabacdc82ede15a9de78dda906e` | `s3fs-public/2026-11/n195-018.jpg` | `public://2026-11/n195-018.jpg` |
| 409 | 183427 | image/jpeg | `931117940a7fef152d788506e8680ee16b507adbd92158120794366b2b0bbc81` | `s3fs-public/2026-11/n195-019.jpg` | `public://2026-11/n195-019.jpg` |
| 410 | 201463 | image/jpeg | `9101796710b7ff373acb53e3921e6659f32462c7f9fcf72ab9fd44c8ba7ddb2d` | `s3fs-public/2026-11/n195-020.jpg` | `public://2026-11/n195-020.jpg` |
| 411 | 173002 | image/jpeg | `f4f307a9ce435a9d6b15ca12e4b9cb3664c035c7d648d477465eeefea2ef68f9` | `s3fs-public/2026-11/n195-021.jpg` | `public://2026-11/n195-021.jpg` |
| 412 | 150501 | image/jpeg | `911126527c76b4a714fb07bde3899fdf3e290a53389583680be8fb1cf926b5e8` | `s3fs-public/2026-11/n195-022.jpg` | `public://2026-11/n195-022.jpg` |
| 413 | 172388 | image/jpeg | `4238015204f7f816504f86f592d81751823d1fbab1f8fdc7e6e98112985e1f1e` | `s3fs-public/2026-11/n195-023.jpg` | `public://2026-11/n195-023.jpg` |
| 414 | 203587 | image/jpeg | `43097ccdccdd955864c03dd606ca82560b4155ae8036230cfbeb9ab960ed21ed` | `s3fs-public/2026-11/n195-024.jpg` | `public://2026-11/n195-024.jpg` |
| 415 | 171057 | image/jpeg | `8fa59a9cf0a0930ea5c81b036446a784918e880e2ac34e18ff08fe9d7b77ff60` | `s3fs-public/2026-11/n195-025.jpg` | `public://2026-11/n195-025.jpg` |
| 416 | 175981 | image/jpeg | `2a7bea30944238715edb2402bf32295cf2cce30e552b969959bf18b8360e9252` | `s3fs-public/2026-11/n195-026.jpg` | `public://2026-11/n195-026.jpg` |
| 417 | 187544 | image/jpeg | `5d93fa63ecaf5bd8b3f934e77d592041e2c8370144a5de51b14c08b549219460` | `s3fs-public/2026-11/n195-027.jpg` | `public://2026-11/n195-027.jpg` |
| 418 | 182408 | image/jpeg | `32496f6dea1310654a7629ba0892fb838558954a933a21aa61a8ffd1f13215ae` | `s3fs-public/2026-11/n195-028.jpg` | `public://2026-11/n195-028.jpg` |
| 419 | 165848 | image/jpeg | `8bf6774db81d46b8725d28e00e2a078b562d1699ff624854c19fee3158a592b2` | `s3fs-public/2026-11/n195-029.jpg` | `public://2026-11/n195-029.jpg` |
| 420 | 144775 | image/jpeg | `c2d1ac284b26dc9e53a8e74e93c2e3436238572047c457e1bfc6285478731967` | `s3fs-public/2026-11/n195-030.jpg` | `public://2026-11/n195-030.jpg` |
| 421 | 161231 | image/jpeg | `c112fb65aeeca4df476cb625e51734cf9aed8b6b9bbbc86e3c72424057e7f52f` | `s3fs-public/2026-11/n195-031.jpg` | `public://2026-11/n195-031.jpg` |
| 422 | 151547 | image/jpeg | `225d29bf1af969a8e3433bd1b6ba2339d3e546eaba6e3f67050fe04c8ec3f6be` | `s3fs-public/2026-11/n195-032.jpg` | `public://2026-11/n195-032.jpg` |
| 423 | 148017 | image/jpeg | `67de905ad52cc686e324ca75f4282895e4a58f0dcaf76f38125dbae645e50136` | `s3fs-public/2026-11/n195-033.jpg` | `public://2026-11/n195-033.jpg` |
| 424 | 182075 | image/jpeg | `1a6779245cc8e5dbbeb60a7686eed57497cc2a1b5f9a7da83dd4b9550fd47ea3` | `s3fs-public/2026-11/n195-034.jpg` | `public://2026-11/n195-034.jpg` |
| 425 | 212245 | image/jpeg | `59d74afe36e0f079849b4a0cb423f694a42c9c1ff43e859b3e8fd847d4450357` | `s3fs-public/2026-11/n195-035.jpg` | `public://2026-11/n195-035.jpg` |
| 426 | 163893 | image/jpeg | `4fa2db43ecc212aff571b235d5223ff47262683471bc8569db4c93cc347ac43e` | `s3fs-public/2026-11/n195-036.jpg` | `public://2026-11/n195-036.jpg` |
| 427 | 145095 | image/jpeg | `9feb430a807392a8b2e48371ee2f3b154bd3c88b17cca9bf3673237e93c2b564` | `s3fs-public/2026-11/n195-037.jpg` | `public://2026-11/n195-037.jpg` |
| 428 | 177929 | image/jpeg | `05bc355bdd27f3aef125d316dc7f963860328ab7d41ed4e6f5f6fa647d3457c9` | `s3fs-public/2026-11/n195-038.jpg` | `public://2026-11/n195-038.jpg` |
| 429 | 173945 | image/jpeg | `224f438fe9cd01439bac4864c2e8ab47bf76296038016897b170e0aaa662ced8` | `s3fs-public/2026-11/n195-039.jpg` | `public://2026-11/n195-039.jpg` |
| 430 | 202032 | image/jpeg | `596e8956f7b32979a120b4a139ff8c60a8ee1883d377f2adf0edf10ad4ad71b1` | `s3fs-public/2026-11/n195-040.jpg` | `public://2026-11/n195-040.jpg` |
| 522 | 1422633 | image/jpeg | `613f3167413ce448ecc8a91aa1ad86ce64e9a846879e4a09120bbfddb1ca38d2` | `s3fs-public/pictures/IMG_9619.jpeg` | `public://pictures/IMG_9619.jpeg` |
| 681 | 3851719 | image/png | `213477d23a89edba2d814d4a840578de61e4f8b208fe36cc10cc4d740b41aac6` | `s3fs-public/2026-11/IMG_2964.png` | `public://2026-11/IMG_2964.png` |
| 682 | 4105189 | image/png | `14f9602bf11978867b8d05e370f702a8a45ce7215cfea6708b2c84ac515b39ca` | `s3fs-public/2026-11/IMG_2717.png` | `public://2026-11/IMG_2717.png` |
| 683 | 4249159 | image/png | `3cae9e7172c50b96c6492e302fd923f76d70f1b26f620f1869de584e4f8111d2` | `s3fs-public/2026-11/IMG_2716.png` | `public://2026-11/IMG_2716.png` |
| 684 | 4391633 | image/png | `5aa6d0df9885276e0c7ac71e5cb7262f13d275923d2f02211ff2a858d0f89984` | `s3fs-public/2026-11/IMG_2714.png` | `public://2026-11/IMG_2714.png` |
| 685 | 4279746 | image/png | `6da4d4a8f879011277bf14cd5be15f153ca4310a6aa8fbbb724ac5b7ce236387` | `s3fs-public/2026-11/IMG_2715.png` | `public://2026-11/IMG_2715.png` |
| 686 | 3920503 | image/png | `9541839311bcea2ceb04b010e5d541d46f63445c83c43cd47106b36190b742b0` | `s3fs-public/2026-11/IMG_2771.png` | `public://2026-11/IMG_2771.png` |

## 阻塞项

- 9 个私有压缩包没有可复制字节。
- 图片和视频的 CDN GET 会把源站 `max-age=60` 改成 `max-age=14400`。未版本化公开对象即使按 3600 秒写入，图片和视频的边缘响应仍可能不是源站值。
- `%252F` 请求匿名读到了测试对象。见 `docs/object-storage-r2-waf-final-test.md`。
- 五个生产开关保持关闭。复制阶段本轮不执行。
