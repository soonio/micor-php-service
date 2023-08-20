# 实仁·八字能量趋势

## 测试环境
```bash
docker run -it --rm --name=energy -v $(pwd):/var/www -p 8101:8101 phpswoole/swoole "php serve.php"
docker run -it --rm --name=energy -v $(pwd):/var/www -p 8101:8101 phpswoole/swoole "php serve.phar"
```

```bash
composer install --ignore-platform-reqs
```

## 打包方案

- 使用[box](https://github.com/box-project/box)
```bash
brew tap humbug/box && brew install box

box help

box compile
```
