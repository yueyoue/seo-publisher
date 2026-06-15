# SEO Publisher

搜索引擎关键词挖掘 & 自动文章发布系统

## 功能特性

- 🔍 **关键词挖掘** - 百度下拉词、相关搜索、大家还在搜
- ✍️ **AI文章生成** - 支持 MiMo / GPT / DeepSeek 等主流模型
- 🌐 **站点管理** - 支持 WordPress 多站点管理
- 📤 **自动发布** - 一键发布到 WordPress 网站
- 👥 **用户系统** - 注册登录、套餐管理、订单管理
- 📊 **数据导出** - 支持 HTML / TXT / Excel 格式

## 环境要求

- PHP >= 7.4
- MySQL >= 5.7
- PHP 扩展: PDO, PDO_MySQL, CURL, JSON, XML

## 安装步骤

1. 上传所有文件到服务器
2. 确保 `config/` 和 `uploads/` 目录可写
3. 访问 `http://你的域名/install/` 进入安装向导
4. 填写数据库信息和管理员账户
5. 安装完成后建议删除或重命名 `install/` 目录

## 目录结构

```
seo-publisher/
├── api/                    # API接口
├── assets/
│   ├── css/               # 样式文件
│   └── js/                # JavaScript文件
├── config/                # 配置文件
├── includes/              # 核心类库
│   ├── layout/            # 页面布局模板
│   ├── Auth.php           # 用户认证
│   ├── Database.php       # 数据库操作
│   ├── AIGenerator.php    # AI文章生成
│   ├── KeywordMiner.php   # 关键词挖掘
│   ├── WordPressPublisher.php  # WordPress发布
│   └── Functions.php      # 公共函数
├── install/               # 安装程序
├── modules/
│   ├── auth/              # 登录注册
│   ├── site/              # 站点管理
│   ├── article/           # 文章生成
│   ├── keyword/           # 关键词挖掘
│   └── user/              # 用户管理
└── uploads/               # 上传文件目录
```

## 使用说明

### 1. 添加网站
进入「站点管理」，点击「添加网站」，填写 WordPress 网站信息。

### 2. 配置全局参数
进入「生成文章」→「全局配置」，设置 AI 模型、API Key、文章参数等。

### 3. 导入关键词
在「生成文章」页面点击「导入关键词」，可以手动输入或导入 TXT 文件。

### 4. 生成文章
点击「开始生成」，系统会自动调用 AI 模型生成文章。

### 5. 发布文章
生成完成后点击「开始发布」，文章将自动发布到配置的 WordPress 网站。

## API Key 获取

- **DeepSeek**: https://platform.deepseek.com/
- **OpenAI GPT**: https://platform.openai.com/
- **MiMo**: https://api.mimo.ai/

## License

MIT License
