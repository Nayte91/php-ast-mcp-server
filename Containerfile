ARG PHP_TAG=alpine

FROM php:${PHP_TAG} as mcp_server
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions ast

COPY system/MCP/mcp_ast_server.php /usr/local/mcp/mcp_ast_server.php

WORKDIR /app

EXPOSE 9005
CMD ["php", "-S", "0.0.0.0:9005", "/usr/local/mcp/mcp_ast_server.php"]
