FROM ubuntu:12.04

MAINTAINER rajendra vm "rajendra.vm87@gmail.com"

RUN apt-get -qq update 

RUN DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-client  mysql-server 

RUN sed -i -e"s/^bind-address\s*=\s*127.0.0.1/bind-address = 0.0.0.0/" /etc/mysql/my.cnf

RUN /usr/sbin/mysqld & \

    sleep 10s &&\

    echo "GRANT ALL ON *.* TO admin@'%' IDENTIFIED BY 'rights' WITH GRANT OPTION; FLUSH PRIVILEGES" | mysql

EXPOSE 3306

CMD ["/usr/bin/mysqld_safe"]



