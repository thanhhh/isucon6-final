FROM golang:alpine

RUN \
  apk add --no-cache curl git make && \
  go get -u github.com/jteeuwen/go-bindata/... && \
  go get -u github.com/Masterminds/glide

WORKDIR ${GOPATH}/src/github.com/isucon/isucon6-final/bench
COPY ./bench ${GOPATH}/src/github.com/isucon/isucon6-final/bench
COPY ./portal ${GOPATH}/src/github.com/isucon/isucon6-final/portal

RUN \
  glide install && \
  make

CMD ["./local-bench", "-urls", "https://react_dev", "-timeout", "30"]
