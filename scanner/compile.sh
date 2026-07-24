#Формат файлов
find . -name "*.c" -o -name "*.h" | xargs clang-format -i

#make clean && make debug
rm scanner.log

make clean && make lto EXTRA_CFLAGS="-march=native -mtune=native"
#chown -R alex:users /home/alex/develop/lopds

#./book_scanner
