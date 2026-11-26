# A WordPress site with WP-CLI, for the plugin's checks. Both images are
# official ones, named by the caller at an explicit version.
ARG WORDPRESS_IMAGE
ARG CLI_IMAGE

FROM ${CLI_IMAGE} AS cli

FROM ${WORDPRESS_IMAGE}
COPY --from=cli /usr/local/bin/wp /usr/local/bin/wp
